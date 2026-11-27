# ADR-005: Host and Scheme Matching

## Status

Proposed — not part of 4.1, which brings the 4.0 code to the Specification first (ADR-007 to ADR-009); retargeted to 4.2

## Date

2026-03-01

## Context

`RouteEntry` carries `host` and `scheme` fields since V4.0.0. The `Controller` and `Route` attributes accept them. However, **no matcher evaluates these fields** — `RouteRegistry::match()` only operates on `(method, uri)`.

This means developers who set `host: 'api.example.com'` or `scheme: 'https'` get no filtering — all routes match regardless of host or scheme. The fields are dead code that creates a false sense of security.

### Use Cases

| Scenario | host | scheme |
|---|---|---|
| Multi-tenant SaaS | `{tenant}.app.com` | `https` |
| API subdomain | `api.example.com` | `https` |
| Admin panel | `admin.example.com` | `https` |
| WebSocket endpoint | `ws.example.com` | `wss` |
| Development override | `localhost` | `http` |

### Competitor Support

| Library | Host Routing | Scheme Enforcement |
|---|---|---|
| Symfony | ✅ (host requirement with params) | ✅ (schemes array) |
| Laravel | ✅ (`Route::domain()`) | ✅ (via middleware) |
| FastRoute | ❌ | ❌ |
| League\Route | ❌ | ❌ |

## Decision

### Extended Match Signature

The `Router` contract gains an optional `MatchContext` parameter:

```php
interface Router
{
    public function match(string $method, string $uri, ?MatchContext $context = null): MatchResult;
}
```

```php
final readonly class MatchContext
{
    public function __construct(
        public ?string $host = null,
        public ?string $scheme = null,
        public ?int $port = null,
    ) {}
}
```

**Backward compatibility:** The parameter is optional with null default. Existing code calling `match('GET', '/path')` continues to work — host/scheme filtering is only applied when `MatchContext` is provided AND the route defines host/scheme constraints.

### Matching Semantics

```
Route has host?  Context has host?  Behavior
─────────────    ────────────────   ────────────────────────
No               No                 Match (no constraint)
No               Yes                Match (no constraint)
Yes              No                 Skip route (constraint unsatisfied)
Yes              Yes                Match only if host matches
```

Same logic for scheme. Host matching supports parameterized patterns:

```php
#[Controller('/', host: '{tenant}.app.com')]
class TenantController { ... }

// Host parameters are merged into MatchResult::parameters
$result = $router->match('GET', '/dashboard', new MatchContext(host: 'acme.app.com'));
$result->parameters->get('tenant'); // 'acme'
```

### Implementation in CompositeRouteMatcher

Host/scheme filtering is a **post-match filter**, not a separate matching tier:

```
1. StaticMatcher::match(method, uri) → candidate?
   └─ if candidate has host/scheme constraint:
      └─ validate against MatchContext → accept or continue

2. RegexMatcher::match(method, uri) → candidate?
   └─ if candidate has host/scheme constraint:
      └─ validate against MatchContext → accept or continue

3. No match → check allowedMethods → 404 or 405
```

**Rationale:** Host constraints affect a small percentage of routes. Making host a first-class matching dimension (separate index) adds complexity without proportional benefit. Post-match filtering on the already-small candidate set is negligible overhead.

### Host Pattern Compilation

Host patterns compile to regex like URI patterns:

```
{tenant}.app.com     → #^(?P<tenant>[^.]+)\.app\.com$#i
api.{version}.ex.com → #^api\.(?P<version>[^.]+)\.ex\.com$#i
*.example.com        → #^[^.]+\.example\.com$#i  (wildcard shorthand)
```

Host matching is case-insensitive (RFC 4343).

## Consequences

### Positive

- Activates dead code — host/scheme fields become functional.
- Multi-tenant SaaS applications can route by subdomain at the router level.
- Host parameters (e.g., `{tenant}`) extracted and available in `MatchResult`.
- Scheme enforcement prevents HTTP access to HTTPS-only routes.
- Backward compatible — no breaking changes to existing API.

### Negative

- `MatchContext` adds a concept to the matching API (mitigated by being optional).
- Protocol adapters must extract host/scheme from protocol-specific objects and pass `MatchContext`.
- Post-match filtering means the router may match a route by URI then reject it by host, falling through to a less specific route — this is correct but may surprise developers used to "first match wins" semantics.

### Migration

Non-breaking. `MatchContext` is optional. Routes without host/scheme constraints behave identically.

## References

- RFC 4343 — Domain Name System Case Insensitivity Clarification
- Symfony host requirements — https://symfony.com/doc/current/routing.html#sub-domain-routing
- Laravel domain routing — https://laravel.com/docs/routing#route-group-subdomain-routing
- ARFA 1.3 — P4 (Protocol Agnosticism): host/scheme are protocol metadata, not protocol coupling

## Note (4.1)

Until this ADR is implemented, `RouteEntry::$host` and `$scheme` are carried to the dispatcher and documented as such (README, `Attribute\Controller`): no matcher reads them, so they must not be relied on for access control.

