# SPEC-001: Router Architecture Specification

## Version

4.0.0 — 2026-03-01

## Abstract

KaririCode\Router is a protocol-agnostic, high-performance routing engine for PHP 8.4+. It resolves `(method, uri)` pairs to typed handler results through a two-tier matching strategy: O(1) hash-map for static routes and compiled group-regex for parameterized routes.

This document specifies the architecture, contracts, type system, matching algorithm, error model, complexity guarantees, ARFA 1.3 compliance mapping, and the V4.1 roadmap.

---

## 1. Architecture Overview

```
┌─────────────────────────────────────────────────────────────────────┐
│                        RouteRegistry                                │
│  implements Router contract                                         │
│  ┌───────────────────────────────────────────────────────────────┐  │
│  │ Fluent API: get() post() put() patch() delete() group()      │  │
│  │ Named route index: name → RouteEntry                          │  │
│  │ Fallback handler (V4.1)                                       │  │
│  └───────────────────────────────┬───────────────────────────────┘  │
│                                  │ delegates                        │
│  ┌───────────────────────────────▼───────────────────────────────┐  │
│  │              CompositeRouteMatcher                             │  │
│  │  ┌─────────────────────┐   ┌──────────────────────────────┐  │  │
│  │  │   StaticMatcher     │   │     RegexMatcher              │  │  │
│  │  │   O(1) hash map     │   │   Compiled group-regex        │  │  │
│  │  │   Literal paths     │──▶│   Parameterized paths         │  │  │
│  │  │                     │   │   mark-based disambiguation   │  │  │
│  │  └─────────────────────┘   └──────────────────────────────┘  │  │
│  └───────────────────────────────────────────────────────────────┘  │
│                                                                     │
│  Optional: CachedMatcher (OPcache file-backed wrapper)              │
└─────────────────────────────────────────────────────────────────────┘

  ┌───────────────────────────────────────────────────────────────────┐
  │                    Integration Bridges                             │
  │  CacheBridge │ EventDispatcherBridge │ ConfiguratorBridge │ Obs.  │
  └───────────────────────────────────────────────────────────────────┘
```

### Request Flow

```
1. Protocol adapter extracts (method, uri) from protocol-specific request
2. RouteRegistry::match(method, uri)
   2a. Normalize URI: ensure leading slash
   2b. Trailing slash policy (V4.1): strip or redirect
3. CompositeRouteMatcher::match(method, uri)
   3a. StaticMatcher: O(1) hash lookup → hit? return MatchResult
   3b. RegexMatcher: iterate compiled chunks → hit? return MatchResult
   3c. Both miss → null
4. RouteRegistry post-match:
   4a. result !== null → return MatchResult
   4b. allowedMethods(uri) non-empty → throw MethodNotAllowedException (405)
   4c. fallback registered? → return MatchResult(fallback) (V4.1)
   4d. throw RouteNotFoundException (404)
```

---

## 2. Contracts

| Interface | Namespace | Responsibility |
|---|---|---|
| `Router` | `Contract\` | `match(method, uri) → MatchResult` — primary routing contract |
| `RouteMatcher` | `Contract\` | Strategy interface for matching algorithms |
| `UrlGenerator` | `Contract\` | `generate(name, params, query) → string` — reverse routing |
| `Middleware` | `Contract\` | Protocol-agnostic middleware with array context (ARFA P4) |

### Router Contract

```php
interface Router
{
    /** @throws RouteNotFoundException|MethodNotAllowedException */
    public function match(string $method, string $uri): MatchResult;
    public function addRoute(RouteEntry $route): void;
    /** @return list<RouteEntry> */
    public function routes(): array;
}
```

Protocol adapters translate protocol-specific objects to `(method, uri)` before calling `match()`. See ADR-002.

### RouteMatcher Contract

```php
interface RouteMatcher
{
    public function match(string $method, string $uri): ?MatchResult;
    public function add(RouteEntry $route): void;
    /** @return list<string> HTTP methods registered for the URI */
    public function allowedMethods(string $uri): array;
}
```

Implementations: `StaticMatcher`, `RegexMatcher`, `CompositeRouteMatcher`, `CachedMatcher`.

---

## 3. Type System

### Classification

| Type | PHP Keyword | Pattern | Mutability | Purpose |
|---|---|---|---|---|
| `RouteEntry` | `final readonly class` | DPO | Immutable | Route definition |
| `MatchResult` | `final readonly class` | DPO | Immutable | Match outcome |
| `RoutePattern` | `final readonly class` | Value Object | Immutable | Compiled pattern + regex |
| `RouteParameters` | `final readonly class` | Value Object | Immutable | Parameter bag |
| `RouteGroup` | `final class` | Builder | Mutable | Collects routes, resolves to `RouteEntry[]` |
| `RouteRegistry` | `final class` | Aggregate | Mutable | Central registry, implements `Router` |
| `HttpMethod` | `enum` (pure) | Taxonomy | Immutable | HTTP verb enumeration |
| `RouteParameterType` | `enum` (pure) | Taxonomy | Immutable | Parameter constraint types |
| `AttributeRouteLoader` | `final class` | Service | Stateless | Discovers routes from PHP 8.4 attributes |
| `RouteUrlGenerator` | `final class` | Service | Stateless | Reverse routing |

### Immutability Guarantee (ARFA P1)

All types marked `final readonly` are deeply immutable:

- No mutable public properties.
- No internal mutation after construction.
- Methods like `RouteEntry::withPrefix()` return new instances.
- Constructor-time compilation (e.g., `RoutePattern::__construct()` builds regex).

### DPO (Data Processing Object) Convention

`RouteEntry` and `MatchResult` carry data between layers without behavior beyond access. They do NOT implement `toArray()`, `fromArray()`, `jsonSerialize()`, or any serialization method (Spec V4.0 compliance). Serialization is the responsibility of the `CachedMatcher` integration layer.

---

## 4. Parameter Type System

### Built-in Types

| Type | Syntax | PCRE Pattern | URL-Safe | Example |
|---|---|---|---|---|
| `String` | `{name}` | `[^/]+` | ✅ | `/users/john` |
| `Int` | `{id:int}` | `[0-9]+` | ✅ | `/users/42` |
| `Slug` | `{slug:slug}` | `[a-z0-9]+(?:-[a-z0-9]+)*` | ✅ | `/posts/hello-world` |
| `Uuid` | `{uuid:uuid}` | `[0-9a-f]{8}-...-[0-9a-f]{12}` | ✅ | `/items/550e8400-...` |
| `Any` | `{path:any}` | `.+` (greedy) | ⚠️ slashes | `/files/a/b/c.txt` |

### Custom Regex (V4.1)

```
{date:regex(\d{4}-\d{2}-\d{2})}  → (?P<date>\d{4}-\d{2}-\d{2})
{token:regex([0-9a-f]{64})}       → (?P<token>[0-9a-f]{64})
```

See ADR-004 for design rationale.

### Optional Parameters (V4.1)

```
/articles/{id:int}[/{slug:slug}]
→ Matches: /articles/42 AND /articles/42/hello-world
```

See ADR-004.

### Constraint Validation

Constraints are validated at two points:

1. **Match time:** The regex pattern enforces the constraint. A non-matching value produces a miss, not an error.
2. **URL generation time:** `RouteUrlGenerator::generate()` validates parameter values against the constraint regex and throws `UrlGenerationException::constraintViolation()` on mismatch.

---

## 5. Matching Algorithm

### StaticMatcher

Data structure: `array<string, array<string, RouteEntry>>` — `uri → method → entry`.

```
match(GET, /health):
  1. $entry = $map['/health']['GET']  → O(1) hash lookup
  2. $entry found → return MatchResult(route: $entry, params: [])
  3. $entry null → return null
```

### RegexMatcher

Data structure: `array<string, list<{regex, routeMap}>>` — `method → chunks`.

Compilation (lazy, on first match):

```
For each method:
  Group routes into chunks of 10
  For each chunk:
    Concatenate route regexes with alternation (|)
    Append empty group () after each route as mark
    Wrap in #^(?:...|...)$#u
    Record mark-group-index → RouteEntry mapping
```

Match:

```
match(GET, /users/42):
  1. method = 'GET'
  2. For each chunk in compiled['GET']:
     a. preg_match(chunk.regex, '/users/42', matches)
     b. hit → find last non-empty group (the mark)
     c. routeMap[markIndex] → RouteEntry
     d. Extract named groups → params
     e. Apply defaults for missing optional params
     f. return MatchResult(route, params, middleware)
  3. All chunks miss → return null
```

### CompositeRouteMatcher

```
match(method, uri):
  1. result = staticMatcher.match(method, uri)
  2. result !== null → return result
  3. return regexMatcher.match(method, uri)
```

### 405 Detection

```
allowedMethods(uri):
  1. static = staticMatcher.allowedMethods(uri)
  2. static non-empty → return static
  3. return regexMatcher.allowedMethods(uri)
```

`RegexMatcher::allowedMethods()` tests the URI against all method-grouped chunks, collecting methods that produce a match. This is O(M·n/c) where M = number of distinct HTTP methods.

---

## 6. Error Model

### Error Code Allocation

| Range | Category | Codes |
|---|---|---|
| 5100–5109 | Route definition | 5100: base RouterException |
| 5110–5119 | Route matching | 5110: RouteNotFoundException (404), 5111: MethodNotAllowedException (405) |
| 5120–5129 | URL generation | 5120: missing parameter, 5121: unknown route, 5122: constraint violation |
| 5130–5139 | Middleware | (reserved) |
| 5140–5149 | Compilation/cache | 5140: RouteCompilationException |
| 5150–5159 | Integration bridge | (reserved) |

### Exception Hierarchy

```
RouterException (5100, extends RuntimeException)
├── RouteNotFoundException (5110)
├── MethodNotAllowedException (5111)
│   └── readonly array $allowedMethods  ← for HTTP Allow header
├── UrlGenerationException (5120–5122)
│   ├── ::missingParameter()
│   ├── ::unknownRoute()
│   └── ::constraintViolation()
└── RouteCompilationException (5140)
```

### Error Recovery

- **404:** Fallback route (V4.1) or caller catch → custom error response.
- **405:** Caller reads `$e->allowedMethods` and sets HTTP `Allow` header per RFC 7231 §6.5.5.
- **URL generation errors:** Caller should validate parameters before generating URLs in user-facing code.
- **Compilation errors:** Fatal at deploy time. Cache write failure should abort deployment.

---

## 7. Complexity Analysis

| Operation | Time Complexity | Space | Notes |
|---|---|---|---|
| Static route match | O(1) | O(1) | Hash-map lookup |
| Parameterized route match | O(n/c) | O(1) | c = chunk size (10), n = parameterized route count |
| 405 detection | O(M·n/c) | O(M) | M = distinct HTTP methods (typically ≤ 7) |
| URL generation | O(p) | O(1) | p = parameters in pattern |
| Route registration | O(1) amortized | O(1) | Invalidates compiled cache |
| Regex compilation | O(n) one-time | O(n) | Lazy, on first match |
| Named route lookup | O(1) | O(1) | Hash-map by name |
| Group resolution | O(g·r) | O(r) | g = nesting depth, r = routes in group |

### Benchmark Targets (V4.1)

| Scenario | Target | Measurement |
|---|---|---|
| 400 static routes, best case | < 0.5 μs/match | hash lookup |
| 400 parameterized routes, avg case | < 3 μs/match | regex with 40 chunks |
| 400 routes, cached (OPcache warm) | < 0.3 μs/match | direct array lookup |
| URL generation, 3 params | < 1 μs/call | regex replace |

---

## 8. ARFA 1.3 Compliance Matrix

| Principle | Requirement | Implementation | Status |
|---|---|---|---|
| P1 — Immutable State | Data objects MUST be immutable after construction | `RouteEntry`, `MatchResult`, `RoutePattern`, `RouteParameters` are `final readonly`. Mutation methods return new instances. | ✅ Compliant |
| P2 — Reactive Flow | Processing MUST compose as data flow pipelines | `MiddlewarePipeline` composes handlers in onion model. Route matching is a pipeline: static → regex → 404/405. | ✅ Compliant |
| P3 — Adaptive Context | System MUST expose metrics for adaptive behavior | `ObservabilityBridge` exposes match/miss counters, latency histogram. Feeds adaptive rate limiting and circuit breakers. | ✅ Compliant |
| P4 — Protocol Agnosticism | Contracts MUST NOT couple to protocol-specific abstractions | `Router::match(string, string)`, `Middleware::handle(array, callable)`. No PSR-7 in contracts. | ✅ Compliant |
| P5 — Continuous Observability | Components MUST emit lifecycle events | `EventDispatcherBridge` emits `router.matched`, `router.not_found`, `router.method_not_allowed`. | ✅ Compliant |

### Spec V4.0 Compliance

| Rule | Status |
|---|---|
| Pure enums (no backed values) | ✅ `HttpMethod`, `RouteParameterType` |
| No `toArray()` / `fromArray()` antipatterns | ✅ |
| No `jsonSerialize()` on domain objects | ✅ |
| Zero external dependencies beyond `kariricode/contract` | ✅ |
| Property hooks where applicable | ✅ `RoutePattern` constructor-time compilation |
| Error code ranges without collision | ✅ 5100–5159 |

---

## 9. Integration Bridges

| Bridge | Ecosystem Package | Purpose |
|---|---|---|
| `CacheBridge` | `kariricode/cache ^4.0` | Route compilation caching via KaririCode Cache |
| `EventDispatcherBridge` | `kariricode/event-dispatcher ^4.0` | Route lifecycle events (matched, not_found, method_not_allowed) |
| `ConfiguratorBridge` | `kariricode/configurator ^4.0` | Load routes from PHP array config files |
| `ObservabilityBridge` | `kariricode/observability ^4.0` | Metrics: match latency, hit/miss counters, 404/405 rates |

All bridges are optional. They accept `object` parameters with duck-typed method checks, avoiding hard dependencies. Bridge instantiation without the ecosystem package is safe — methods gracefully no-op when the dependency is absent.

---

## 10. Component Inventory

### Source (`src/`)

| Directory | Files | Purpose |
|---|---|---|
| `Attribute/` | 2 | `#[Route]`, `#[Controller]` PHP 8.4 attributes |
| `Contract/` | 4 | Router, RouteMatcher, UrlGenerator, Middleware |
| `Core/` | 5 | RouteRegistry, RouteEntry, MatchResult, RouteGroup, AttributeRouteLoader |
| `Enum/` | 2 | HttpMethod, RouteParameterType (pure enums) |
| `Exception/` | 5 | RouterException hierarchy (5100–5159) |
| `Generator/` | 1 | RouteUrlGenerator (reverse routing) |
| `Integration/` | 4 | Cache, EventDispatcher, Configurator, Observability bridges |
| `Matcher/` | 4 | StaticMatcher, RegexMatcher, CompositeRouteMatcher, CachedMatcher |
| `Middleware/` | 1 | MiddlewarePipeline (onion model) |
| `ValueObject/` | 2 | RoutePattern, RouteParameters |
| **Total** | **30** | **~2,025 LOC** |

### Tests (`tests/`)

| Directory | Files | Tests | Coverage |
|---|---|---|---|
| `Unit/` | 5 | ~50 | RoutePattern, RouteRegistry, UrlGenerator, Matchers, Middleware |
| `Integration/` | 1 | ~10 | Full lifecycle: attributes, groups, URL gen, observability |
| **Total** | **6** | **~60** | |

### Documentation (`docs/`)

| Document | Path |
|---|---|
| ADR-001: Composite Matching Strategy | `docs/adr/ADR-001-composite-matching-strategy.md` |
| ADR-002: Protocol-Agnostic Design | `docs/adr/ADR-002-protocol-agnostic-design.md` |
| ADR-003: OPcache Route Caching | `docs/adr/ADR-003-opcache-route-caching.md` |
| ADR-004: Optional Params & Custom Constraints | `docs/adr/ADR-004-optional-params-custom-constraints.md` |
| ADR-005: Host & Scheme Matching | `docs/adr/ADR-005-host-scheme-matching.md` |
| ADR-006: Trailing Slash & Fallback Routes | `docs/adr/ADR-006-trailing-slash-and-fallback.md` |
| SPEC-001: Architecture (this document) | `docs/spec/SPEC-001-router-architecture.md` |
| Code Review V4.0 | `docs/review/CODE-REVIEW-V4.0.md` |

---

## 11. Roadmap

### V4.0.0 (Current)

- ✅ Two-tier composite matching (static + regex)
- ✅ Typed parameter constraints (5 types)
- ✅ PHP 8.4 attribute routing
- ✅ Route groups with nesting
- ✅ URL generation with constraint validation
- ✅ Protocol-agnostic contracts
- ✅ Middleware pipeline
- ✅ 4 ecosystem bridges
- ⚠️ CachedMatcher: warm/match not fully implemented (marker only)
- ⚠️ Host/scheme: fields exist but not matched

### V4.1.0 (Planned)

| Feature | ADR | Priority |
|---|---|---|
| Optional parameters `[/{slug}]` | ADR-004 | P0 |
| Custom regex constraints `{date:regex(...)}` | ADR-004 | P0 |
| Working OPcache route caching | ADR-003 | P0 |
| Host/scheme matching with `MatchContext` | ADR-005 | P1 |
| Trailing slash normalization | ADR-006 | P1 |
| Fallback route | ADR-006 | P1 |
| `where()` fluent constraint API | ADR-004 | P1 |
| Route debugging/dump | — | P2 |
| PSR-14 typed event objects | — | P2 |

### V4.2.0 (Future)

| Feature | Description |
|---|---|
| `CompiledMatcher` | Symfony-style PHP class generation for zero-runtime-regex matching |
| Resource routes | `$router->resource('/users', UserController::class)` auto-CRUD |
| Middleware priority | Numeric priority for execution ordering |
| Constraint composition | `{id:int\|min:1\|max:999}` chained constraints |
| Route versioning | Built-in API versioning via header/URI/query |

---

## 12. Competitive Position

### Unique Advantages (no competitor offers all)

1. **Protocol-agnostic core** — HTTP, gRPC, WebSocket, CLI with same engine (only KaririCode + FastRoute)
2. **Typed immutable results** — `final readonly MatchResult` (only KaririCode; competitors return arrays)
3. **Two-tier automatic classification** — static O(1) + compiled regex without developer configuration
4. **Deep ecosystem integration** — Cache, EventDispatcher, Configurator, Observability bridges
5. **Zero external deps** — only `kariricode/contract` (matches FastRoute; lighter than Symfony/Laravel)
6. **PHP 8.4 attributes + groups** — attribute routing with full inheritance (matches Symfony; exceeds FastRoute)

### Parity Gaps to Close (V4.1)

1. Optional parameters (FastRoute, Symfony, Laravel have it)
2. Custom regex constraints (FastRoute, Symfony, Laravel have it)
3. Working production caching (Symfony, Laravel have it)
4. Host/scheme matching (Symfony, Laravel have it)
5. Trailing slash normalization (Symfony, Laravel have it)

### Market Positioning After V4.1

With V4.1 features implemented, KaririCode\Router would be the **only PHP router** that combines:
- Protocol-agnostic core (no PSR-7 coupling)
- Two-tier O(1)/regex matching with automatic classification
- Typed immutable result objects
- PHP 8.4 attribute routing
- Custom regex + typed constraints
- Optional parameters
- Host/domain routing
- OPcache production caching
- Zero external dependencies
- Deep framework ecosystem integration

No existing library (FastRoute, Symfony, Laravel, League) offers all of these simultaneously.
