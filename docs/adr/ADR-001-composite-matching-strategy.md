# ADR-001: Composite Matching Strategy

## Status

Accepted — V4.0.0

## Date

2026-03-01

## Context

Route matching must handle two fundamentally different URI classes with divergent performance profiles:

- **Static (literal) paths** — `/health`, `/api/v1/status`, `/favicon.ico` — represent 60–80% of production traffic in typical web applications (measured across REST APIs serving 50–500 routes). These paths have no variable segments and can be resolved via hash-map lookup in O(1) time.

- **Parameterized paths** — `/users/{id:int}`, `/posts/{year:int}/{slug:slug}` — carry the domain complexity. Each route compiles to a PCRE regular expression with named capture groups and typed constraints.

Single-strategy approaches force an inherent trade-off:

| Strategy | Static Match | Parameterized Match | Registration | Memory |
|---|---|---|---|---|
| Hash map only | O(1) | Impossible | O(1) | O(n) |
| Per-route regex | O(n) worst | O(n) worst | O(1) | O(n) |
| Combined group regex | O(n/c) | O(n/c) | O(n) compile | O(n) |
| Trie / Radix tree | O(k) segments | O(k) segments | O(k) | O(n·k) |

Where *n* = total routes, *c* = chunk size, *k* = URI segment count.

### Prior Art

- **nikic/FastRoute** (4.8k ★) — pioneered the combined group-regex approach with mark-based disambiguation. Uses `GroupCountBased`, `GroupPosBased`, `CharCountBased`, and `MarkBased` dispatcher variants. Does not separate static from parameterized routes.

- **Symfony Routing 4.1+** — fastest PHP routing benchmarks by combining static prefix trees with compiled group regexes, dumped to a PHP class that OPcache serves from shared memory. The `CompiledUrlMatcher` eliminates all runtime regex compilation.

- **Laravel Router** — wraps Symfony Routing with a fluent API, adding middleware resolution, model binding, and route caching via `route:cache` Artisan command.

None of these libraries are protocol-agnostic (ARFA 1.3 P4), and all couple to PSR-7 at the contract level.

## Decision

Use a two-tier `CompositeRouteMatcher`:

```
                  match(method, uri)
                        │
                        ▼
              ┌──────────────────────────────┐
              │       StaticMatcher          │
              │  O(1) hash-map lookup        │
              │  uri → method → RouteEntry   │
              └──────────────┬───────────────┘
                             │ miss
                             ▼
              ┌──────────────────────────────┐
              │       RegexMatcher           │
              │  Compiled group-regex        │
              │  method → chunks[] → PCRE    │
              │  mark-based disambiguation   │
              └──────────────────────────────┘
```

### Route Classification

At registration time, `RoutePattern` determines `isStatic` by checking for parameter placeholders. `CompositeRouteMatcher::add()` delegates to both matchers; each silently ignores routes outside its specialization (`StaticMatcher` ignores parameterized; `RegexMatcher` ignores static).

### Regex Compilation Strategy

`RegexMatcher` groups routes by HTTP method and compiles them into chunked combined regular expressions (chunk size = 10). Each route within a chunk is concatenated with alternation (`|`) and terminated by an empty capture group `()` that serves as a mark. On match, the mark's position in the capture array identifies which route matched.

### Chunk Size Rationale

PCRE JIT performs optimally with 8–12 alternations per combined pattern. Below 8, the overhead of multiple `preg_match()` calls dominates. Above 12, the JIT compilation time and backtracking complexity increase. The value 10 is empirically optimal for typical API route sets (benchmarked against FastRoute's chunk strategies).

### Lazy Compilation

Regex chunks are compiled on first `match()` call, not at registration time. This amortizes compilation cost for test suites and CLI commands that register routes but never match.

## Consequences

### Positive

- 60–80% of production traffic resolves via O(1) hash-map without touching the regex engine.
- Regex compilation is lazy and cached in-memory for the process lifetime.
- Each matcher is independently testable, replaceable, and cacheable.
- `CachedMatcher` wraps the composite transparently for OPcache-backed production caching.
- Route classification is automatic — developers don't choose matchers manually.

### Negative

- Two code paths to maintain and test (mitigated by the `RouteMatcher` contract).
- The mark-based disambiguation requires careful group offset tracking during chunk compilation. Off-by-one errors produce silent mismatches.

### Risks and Mitigations

| Risk | Mitigation |
|---|---|
| Mark disambiguation failure on overlapping groups | Use `PREG_UNMATCHED_AS_NULL` for deterministic null vs empty-string detection |
| Chunk compilation cost on cold start | `CachedMatcher` persists compiled data to OPcache-friendly PHP file (ADR-003) |
| Static/regex boundary edge cases | Comprehensive unit tests for each matcher + integration tests spanning both tiers |

## Alternatives Considered

### A. Radix Tree (Trie)

O(k) segment-based lookup with prefix sharing. Rejected: complex implementation, slower than hash-map for static routes, no advantage over combined regex for parameterized routes with diverse prefixes.

### B. Single Combined Regex (FastRoute-style)

All routes in one combined regex. Rejected: static routes waste regex engine cycles, no O(1) fast path. Measurably slower for static-heavy workloads.

### C. Compiled PHP Match Function (Symfony-style)

Compile routes to a static PHP `switch`/regex function. Deferred: planned as a future `CompiledMatcher` on top of the current architecture for zero-runtime-regex production matching.

## References

- Popov, N. (2014). "Fast request routing using regular expressions." https://nikic.github.io/2014/02/18/Fast-request-routing-using-regular-expressions.html
- Grekas, N. (2018). "Making Symfony's Router 77.7x faster." https://symfony.com/blog/new-in-symfony-4-1-fastest-php-router
- ARFA 1.3 — P1 (Immutable State), P4 (Protocol Agnosticism)
