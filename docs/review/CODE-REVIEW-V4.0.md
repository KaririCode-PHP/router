# KaririCode\Router V4.0 — Code Review & Competitive Gap Analysis

**Reviewer:** Claude (Architectural Review)
**Date:** 2026-03-01
**Scope:** Full source (30 files, 2,025 LOC), tests (6 files, 774 LOC), docs (4 files)
**Methodology:** Line-by-line source audit + competitive matrix against FastRoute 2.x, Symfony Routing 7.x, Laravel Router 12.x, League\Route 5.x

---

## 1. Critical Bugs

### BUG-001: CachedMatcher is a no-op (Severity: HIGH)

`CachedMatcher::warm()` writes `['warmed' => true, 'timestamp' => time()]` — a trivial marker. It never serializes the actual compiled regex data from `RegexMatcher`. The `match()` method unconditionally delegates to `$this->inner` without ever reading the cache file.

**Impact:** The entire OPcache caching story (ADR-003) is aspirational, not implemented. Production deployments recompile regexes on every process.

**Fix:** `warm()` must call `RegexMatcher::exportCompiled()`, serialize the chunk data + static map into a PHP `return` array, and `match()` must load from cache when available, bypassing inner compilation.

### BUG-002: CacheBridge::warmOrRestore() is functionally dead (Severity: HIGH)

The method returns `true` on cache hit but the comment explicitly says: *"Cached data exists — but we still need routes registered in the matcher."* The bridge stores `['warmed' => true, 'count' => N]` — no route data. This is pure ceremony that cannot accelerate anything.

**Fix:** Either remove the bridge (since CachedMatcher should own caching) or implement full route serialization/deserialization.

### BUG-003: RegexMatcher mark-based disambiguation is fragile (Severity: MEDIUM)

The match logic walks backwards from the last capture group to find the "mark" (the empty `()` appended after each route). This breaks when:

- A named group in an earlier route captures an empty string (optional param edge case).
- Two routes in the same chunk have overlapping group counts.

The original nikic/FastRoute uses `MARK` (`(*MARK:n)`) or counted groups with known offsets. The current implementation uses a heuristic backward walk that is not formally correct.

**Fix:** Use `PREG_UNMATCHED_AS_NULL` (PHP 7.2+) and check for the mark group by its known offset position instead of walking backwards.

### BUG-004: RouteGroup nested name prefix duplication (Severity: LOW)

Test `testFluentApiWithGroupsAndUrlGeneration` asserts `'api.admin.admin.stats'` — the `admin.` prefix appears twice because `RouteGroup::applyGroupAttributes()` prepends `$this->namePrefix` and the inner group's callback also sets `namePrefix: 'admin.'`.

**Impact:** Confusing DX — developers must mentally track prefix accumulation across nesting levels.

**Fix:** Document the behavior explicitly or add deduplication logic in `applyGroupAttributes()`.

### BUG-005: UrlGenerator double-encodes {path:any} parameters (Severity: LOW)

`rawurlencode()` is applied to all parameter values, but `{path:any}` captures `docs/2025/report.pdf` — the slashes get encoded to `%2F`, producing `/files/docs%2F2025%2Freport.pdf`.

**Fix:** Skip encoding for `RouteParameterType::Any` or use a slash-preserving encode.

---

## 2. Architectural Issues

### ARCH-001: host and scheme fields are dead code

`RouteEntry` carries `host` and `scheme`, `Controller` and `Route` attributes accept them, but **no matcher ever reads them**. `RouteRegistry::match()` only operates on `(method, uri)`.

**Impact:** Developers who set `host: 'api.example.com'` get no filtering — all routes match regardless of host.

**Fix:** ADR-004 below proposes host/scheme matching in the routing pipeline.

### ARCH-002: No optional parameter support

FastRoute supports `[/{title}]`, Laravel supports `{id?}`. KaririCode has no optional parameter syntax. Developers must register two routes for every optional segment.

**Impact:** Route count explosion in real-world APIs (e.g., `/articles/{id}[/{title}]` requires 2 entries).

### ARCH-003: No custom regex constraints per parameter

FastRoute allows `{id:\d+}` with arbitrary PCRE. KaririCode limits to 5 predefined types (int, string, slug, uuid, any). APIs with custom formats (e.g., `{date:20\d{2}-\d{2}-\d{2}}`) cannot express constraints without post-match validation.

### ARCH-004: No trailing slash normalization policy

`/users` and `/users/` are different routes. No configuration to normalize (redirect or strip). Every framework competitor offers this.

### ARCH-005: No fallback/catch-all route

Laravel's `Route::fallback()` and Symfony's default route. KaririCode throws `RouteNotFoundException` with no fallback mechanism.

### ARCH-006: EventDispatcherBridge uses stdClass for events

PSR-14 dispatchers require `object` but many implementations type-hint specific event classes or interfaces. Using `\stdClass` with dynamic properties is unreliable and triggers deprecation warnings in PHP 8.2+.

---

## 3. Competitive Feature Matrix

| Feature | KaririCode | FastRoute 2.x | Symfony 7.x | Laravel 12.x | League 5.x |
|---|:---:|:---:|:---:|:---:|:---:|
| Static O(1) hash lookup | ✅ | ❌ | ✅ | ✅ | ❌ |
| Compiled group regex | ✅ | ✅ | ✅ | ✅ | ❌ |
| OPcache file cache | ⚠️ stub | ✅ | ✅ | ✅ | ❌ |
| Typed parameter constraints | ✅ (5 types) | ✅ (custom regex) | ✅ (custom regex) | ✅ (custom regex) | ❌ |
| Optional parameters `[/opt]` | ❌ | ✅ | ✅ | ✅ | ❌ |
| Custom regex per param | ❌ | ✅ | ✅ | ✅ | ❌ |
| Host/domain routing | ⚠️ dead code | ❌ | ✅ | ✅ | ❌ |
| Scheme enforcement | ⚠️ dead code | ❌ | ✅ | ✅ | ❌ |
| Trailing slash normalization | ❌ | ❌ | ✅ | ✅ | ❌ |
| Fallback/catch-all | ❌ | ❌ | ✅ | ✅ | ❌ |
| Route priority/ordering | ❌ | ❌ | ✅ | ✅ | ❌ |
| Reverse routing (URL gen) | ✅ | ❌ | ✅ | ✅ | ✅ |
| PHP Attribute routing | ✅ | ❌ | ✅ | ✅ | ❌ |
| Route groups + nesting | ✅ | ✅ (flat) | ✅ | ✅ | ✅ |
| Protocol agnostic | ✅ | ✅ | ❌ (PSR-7) | ❌ (PSR-7) | ❌ (PSR-7) |
| Middleware pipeline | ✅ | ❌ | ✅ | ✅ | ✅ |
| Route debugging/listing | ❌ | ❌ | ✅ (`route:list`) | ✅ (`route:list`) | ❌ |
| Route caching CLI | ❌ | ❌ | ✅ (`route:cache`) | ✅ (`route:cache`) | ❌ |
| Zero external deps | ✅ | ✅ | ❌ | ❌ | ❌ |
| Middleware priority/sorting | ❌ | N/A | ✅ | ✅ | ❌ |

**Legend:** ✅ = implemented, ⚠️ = partial/broken, ❌ = absent

---

## 4. Opportunities to Become Market Leader

### 4.1 Must-Have (Close Parity Gaps)

1. **Optional parameters** — `{title?}` or `[/{title}]` syntax
2. **Custom regex constraints** — `{date:\d{4}-\d{2}-\d{2}}` alongside typed presets
3. **Working OPcache caching** — fix CachedMatcher to actually serialize/restore compiled data
4. **Host + scheme matching** — activate the dead code path
5. **Trailing slash policy** — configurable: strict, redirect, or normalize

### 4.2 Differentiators (Unique Value)

6. **Protocol adapters as first-class** — ship gRPC, WebSocket, CLI adapter examples in the package
7. **Route compilation to PHP class** — generate a static match function (like Symfony's CompiledUrlMatcher) that eliminates all runtime regex for production
8. **Constraint composition** — `{id:int|min:1|max:999}` chained constraints (no competitor does this)
9. **Route versioning** — built-in API versioning via header/URI/query with deprecation metadata
10. **Debug dump** — `RouteRegistry::dump(): array` returning a structured route table for CLI tools

### 4.3 Quality-of-Life

11. **Fallback route** — `$router->fallback(handler)`
12. **Route middleware priority** — numeric priority for execution ordering
13. **`where()` per-route constraints** — `$router->get('/u/{name}', h)->where('name', '[a-z]+')` fluent API
14. **Resource routes** — `$router->resource('/users', UserController::class)` auto-generates CRUD

---

## 5. Documentation Quality Assessment

### ADRs — Issues Found

- **ADR-001:** References "Radix Tree" in SPEC and class docblocks but no Radix Tree exists — it's a compiled group-regex. Terminology mismatch confuses readers.
- **ADR-003:** Describes a caching strategy that is not implemented. The doc implies full route serialization but the code only writes a marker file.
- **Missing ADR:** No ADR for optional parameters design decision (blocking feature).
- **Missing ADR:** No ADR for host/scheme matching strategy.

### SPEC-001 — Issues Found

- References "Radix Tree" in the ASCII diagram — should say "Compiled Group Regex"
- Complexity table claims O(n/c) for parameterized match but doesn't account for chunk iteration
- No section on error recovery or graceful degradation
- No section on thread safety / process model assumptions
- No versioning/migration guidance

---

## 6. Test Coverage Gaps

| Area | Coverage | Missing |
|---|---|---|
| StaticMatcher | Good | Edge: empty URI, encoded URIs |
| RegexMatcher | Good | Edge: overlapping params, 12+ routes per chunk |
| CachedMatcher | **None** | warm(), clear(), isCached(), cache invalidation |
| CacheBridge | **None** | warmOrRestore(), invalidate() |
| ConfiguratorBridge | **None** | loadFile(), loadInto(), group loading |
| EventDispatcherBridge | **None** | routeMatched(), routeNotFound() |
| ObservabilityBridge | Basic | recordMethodNotAllowed(), reset() |
| AttributeRouteLoader | Good via integration | Edge: no Route attributes, abstract methods |
| RouteGroup | Good via integration | 3+ nesting levels, empty groups |
| UrlGenerator | Good | Edge: {path:any} encoding, special chars |
| MiddlewarePipeline | Good | Edge: exception in middleware, 50+ layers |

**Missing test files:** CachedMatcher, CacheBridge, ConfiguratorBridge, EventDispatcherBridge (4 untested classes).
