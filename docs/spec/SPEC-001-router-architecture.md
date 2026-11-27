# SPEC-001: Router Architecture

## Version

4.1 — 2026-11-27. Supersedes the 4.0.0 text of 2026-03-01, which described matching, caching and bridges that the 4.0 code did not deliver (code review V4.0).

## Abstract

KaririCode\Router resolves a `(method, path)` pair of plain strings to the route that serves it. Static paths resolve through a hash map; paths with placeholders through a few combined PCRE expressions per method, built with branch reset and MARK verbs. Routes come from a fluent API, groups, PHP attributes or arrays; every definition is checked when it is registered. The compiled table can be cached in an OPcache file or a PSR-16 cache, and match outcomes can be observed without coupling the router to any backend.

This document specifies the model, the pattern syntax, matching, URL generation, errors and observability. Decisions and their alternatives live in the ADRs; measured quality lives in `docs/quality/QD-V433-COMPLIANCE.md`.

---

## 1. Overview

```
 protocol adapter (kariricode/http, grpc, websocket, …)
        │  RequestNormalizer → NormalizedRequest(method, path)
        ▼
 ObservedRouter (optional) ──▶ MatchObserver: MatchStatistics, EventDispatcherBridge (PSR-14)
        │
        ▼
 RouteRegistry ── names, duplicates, leading "/" ──┐
        │ match()                                   │ addRoute()
        ▼                                           ▼
 RouteMatcher: CompositeRouteMatcher  or  CachedMatcher ──▶ RouteCacheStore
                ├─ StaticMatcher (hash map)            ├─ PhpFileStore (OPcache file)
                └─ RegexMatcher (MARK chunks)          └─ SimpleCacheStore (PSR-16)
        │
        ▼
 MatchResult(route, parameters, middleware) ──▶ dispatcher: MiddlewarePipeline, handler
```

Request flow:

1. The adapter passes the method and the path, without query string.
2. `RouteRegistry::match()` adds a missing leading "/".
3. The matcher tries the static map, then the expressions of that method.
4. On a hit, a `MatchResult` is returned. On a miss, `allowedMethods()` decides: a non-empty list throws `MethodNotAllowedException` (405), an empty one `RouteNotFoundException` (404).

The router never calls a handler and never reads a protocol's request type (ADR-002).

---

## 2. Routes and registration

### 2.1 RouteEntry

A route is a `final readonly` value: methods, pattern, handler, and the optional name, middleware, defaults, metadata, host and scheme.

| Field | Rule |
|---|---|
| methods | non-empty; each an RFC 9110 token starting with a letter; stored uppercase, without repeats |
| pattern | compiled at construction (§3) |
| handler | a non-empty string, a `[class, method]` pair of non-empty strings, or a Closure; a definition the dispatcher resolves |
| name | unique in a registry |
| middleware | definitions the dispatcher resolves, outermost first |
| defaults | strings for parameters the path did not provide |
| metadata | free-form; only scalars, null and arrays of those can be cached |
| host, scheme | carried, not matched (ADR-005, proposed) |

`withPrefix()`, `withPrependedMiddleware()` and `withNamePrefix()` return copies.

### 2.2 RouteRegistry

The registry implements `Contract\Router`. `route()` registers methods of any protocol; `get()` (GET and HEAD), `post()`, `put()`, `patch()` and `delete()` are HTTP shortcuts; `group()` collects a `RouteGroup`; `addRoute()` takes a ready entry. `addRoute()` rejects a pattern that does not start with "/", a second route for a method and pattern already taken, and a name already taken (ADR-007). A rejected route changes nothing. `namedRoute()` is the nullable lookup URL generation uses.

### 2.3 Groups

`RouteGroup` prefixes patterns, prepends middleware and prefixes names. Groups nest; the inner group applies first, so 'stats' in group 'admin.' inside group 'api.' is 'api.admin.stats', with the outer middleware first. An empty pattern stands for the prefix.

### 2.4 Attributes

`#[Controller(prefix, middleware, namePrefix, host, scheme)]` on a class and `#[Route(pattern, method, name, middleware, defaults, host, scheme)]` on public methods. `AttributeRouteLoader` returns one entry per `#[Route]` (the attribute repeats), with `[class, method]` as handler. A route without pattern uses "/" plus the method name; the prefix and pattern are joined as written, minus a trailing "/" of the prefix.

### 2.5 Route files

`ArrayRouteLoader::loadFile()` executes a PHP file that returns an array; `load()` takes the array. A route is `[methods, pattern, handler, name?]` with optional `middleware` and `defaults` keys; methods may be "GET|POST" or a list. A group is `['group' => prefix, 'routes' => [...]]` with optional `middleware` and `name_prefix`. The first entry of the wrong shape throws `RouteFileException` with its position.

### 2.6 Types

src/ holds 40 types; the conformance suite checks the count.

| Namespace | Types |
|---|---|
| Attribute | Controller, Route |
| Cache | PhpFileStore |
| Contract | MatchObserver, Middleware, RequestNormalizer, RouteCacheStore, RouteMatcher, Router, UrlGenerator |
| Core | ArrayRouteLoader, AttributeRouteLoader, MatchResult, MatchStatistics, NormalizedRequest, ObservedRouter, RouteEntry, RouteGroup, RouteRegistry |
| Enum | HttpMethod (deprecated), RouteParameterType |
| Event | RouteMatched, RouteMissed |
| Exception | MethodNotAllowedException, RouteCacheException, RouteCompilationException, RouteFileException, RouteNotFoundException, RouterCode, UrlGenerationException |
| Generator | RouteUrlGenerator |
| Integration | EventDispatcherBridge (PSR-14), SimpleCacheStore (PSR-16) |
| Matcher | CachedMatcher, CompositeRouteMatcher, RegexMatcher, StaticMatcher |
| Middleware | MiddlewarePipeline |
| ValueObject | RouteParameters, RoutePattern |

---

## 3. Pattern syntax

Literal text matches itself. A placeholder is `{name}` or `{name:type}`; the name matches `[A-Za-z_][A-Za-z0-9_]*` and appears once per pattern.

| Type | Fragment | Matches |
|---|---|---|
| string (default) | `[^/\x00-\x1F\x7F]+` | one segment |
| int, integer | `[0-9]+` | decimal digits |
| slug | `[a-z0-9]+(?:-[a-z0-9]+)*` | lowercase words joined by "-" |
| uuid | `[0-9a-fA-F]{8}-…-[0-9a-fA-F]{12}` | 8-4-4-4-12 hexadecimal, any case (RFC 9562 §4) |
| any | `[^\x00-\x1F\x7F]+` | the rest of the path, "/" included |

- No type matches a control character: RFC 3986 paths carry them percent-encoded.
- Patterns match the raw path, without percent-decoding.
- Anything else fails at construction with `RouteCompilationException`: a brace outside a placeholder (5000), a malformed placeholder (5000), a repeated name (5001), an unknown type such as `{id:\d+}` or `{id:integr}` (5002). Custom constraints and optional segments are ADR-004, proposed.

`RoutePattern` compiles each pattern twice: `$regex`, anchored with `\A` and `\z` and one named group per parameter, matches the pattern alone; `$fragment`, with positional groups and no anchors, is what RegexMatcher joins.

---

## 4. Match result and parameters

`MatchResult` holds the route, a `RouteParameters` bag and the middleware list. Parameters are the path's values merged over the route's defaults, as strings. `getInt()` converts a decimal integer that fits `PHP_INT_MAX` and returns the default otherwise. `all()` hands the map on, for request attributes.

---

## 5. Matching

### 5.1 StaticMatcher

Routes without placeholders, in `path → method → route`. One array lookup per request. The first route registered for a method and path keeps it.

### 5.2 RegexMatcher

Routes with placeholders, grouped by method, in chunks of 30. A chunk is:

```
#\A(?|/users/([0-9]+)(*MARK:0)|/posts/([^/\x00-\x1F\x7F]+)/([^/\x00-\x1F\x7F]+)(*MARK:1))\z#u
```

Branch reset numbers the groups of each alternative from 1; `$matches['MARK']` is the index of the route that matched; captures map to the route's parameter names by position. Within a method, the first route registered wins. Compilation is lazy and repeats after `add()`. An invalid UTF-8 path never matches (the `u` modifier rejects it). This replaces the 4.0 empty-group mark, which never found a route (ADR-001, Amendment).

### 5.3 CachedMatcher

`warm()` compiles a `CompositeRouteMatcher` into a table: format version, SHA-256 checksum of the routes, the static map and the chunks, with routes as arrays. A `RouteCacheStore` keeps it: `PhpFileStore` (atomic rename, OPcache invalidation) or `SimpleCacheStore` (PSR-16, key checked at construction). A process that finds a table of this format serves every match from it, rebuilding only the matched route. If the inner matcher has routes, the table must match their checksum, so a stale table is ignored. Closure handlers, object metadata or another inner matcher fail at `warm()` (ADR-003, Amendment).

### 5.4 405 detection

`allowedMethods(path)` lists every method for which some route matches the path, static and pattern tiers joined, without repeats. `RouteRegistry` turns a non-empty list into `MethodNotAllowedException::allowing()`.

---

## 6. URL generation

`RouteUrlGenerator::generate(name, parameters, query)`:

1. finds the route by name, or throws `RouteNotFoundException::named()` (5021);
2. replaces each placeholder with the parameter, else the route default, else throws `UrlGenerationException` (5020);
3. checks the value against the placeholder's type (5022) and percent-encodes it; an `any` value is encoded per segment, keeping "/";
4. appends parameters that are not placeholders, then `query`, as an RFC 3986 query string;
5. prepends the base from `withBase()`.

---

## 7. Errors

Every exception extends a family base of kariricode/exception 2.0, carries a `RouterCode` in block 5000–5099 (prefix RO) and has a message that names the rule, never client data (ADR-007).

| Range | Stage | Exceptions |
|---|---|---|
| 5000–5006 | route definitions | RouteCompilationException (Configuration) |
| 5010–5011 | matching | RouteNotFoundException, MethodNotAllowedException (NotFound) |
| 5020–5022 | URL generation | UrlGenerationException (Validation), RouteNotFoundException::named() (NotFound) |
| 5040–5042 | cache table and key | RouteCompilationException (Configuration) |
| 5045–5046 | cache storage | RouteCacheException (Runtime) |
| 5050–5052 | route files | RouteFileException (Configuration) |

---

## 8. Observability

`ObservedRouter` decorates a `Router`: it times `match()` on `hrtime()` and calls `MatchObserver::routeMatched()` or `routeMissed()` before returning or rethrowing. Observers receive the method and the result or failure, never the path (Spec V4.3.3 §23.7). `MatchStatistics` counts in memory; `EventDispatcherBridge` dispatches `RouteMatched` and `RouteMissed` to a PSR-14 dispatcher (ADR-009).

---

## 9. Middleware

`Contract\Middleware::handle(array $context, callable $next)` works on the adapter's context array, not on PSR-7. `MiddlewarePipeline::execute()` runs the list onion-style, first entry outermost; a layer short-circuits by returning without calling `$next`. Resolving the definitions in `MatchResult::$middleware` to instances is the dispatcher's job.

---

## 10. Complexity

| Operation | Cost |
|---|---|
| static match | O(1) |
| pattern match | O(n / 30) `preg_match` calls for n patterns of the method |
| 405 detection | one pass over the chunks of each method |
| registration | O(1) amortised; compiles the pattern; invalidates the chunks |
| chunk compilation | O(n), lazy, once per process (or never, with a cached table) |
| URL generation | O(p) for p placeholders |

---

## 11. ARFA 1.3

| Principle | How |
|---|---|
| P1 Immutable state | RouteEntry, RoutePattern, RouteParameters, MatchResult, the events and the exceptions' data are readonly; withers copy |
| P2 Flow composition | static → patterns → 405/404; MiddlewarePipeline |
| P3 Adaptive optimisation | compiled table in OPcache or PSR-16; lazy compilation |
| P4 Protocol agnosticism | string methods and paths; normalizers live in protocol packages |
| P5 Continuous observability | ObservedRouter with MatchObserver; PSR-14 events |

---

## 12. Roadmap

- 4.1: this specification.
- 4.2 candidates: optional segments and custom constraints (ADR-004), host and scheme matching (ADR-005), trailing-slash policy and fallback route (ADR-006), a DI provider for kariricode/dependency-injection.
- 5.0: `Enum\HttpMethod` removed.
