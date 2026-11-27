# ADR-006: Trailing Slash Normalization and Fallback Routes

## Status

Proposed — not part of 4.1, which brings the 4.0 code to the Specification first (ADR-007 to ADR-009); retargeted to 4.2

## Date

2026-03-01

## Context

### Trailing Slash Ambiguity

`/users` and `/users/` are distinct URIs per RFC 3986. However, most web applications treat them as equivalent. Current behavior: they are different routes. A `GET /users` registration does not match `GET /users/`. This causes silent 404s that are difficult to debug.

| Library | Default Behavior | Configurable |
|---|---|---|
| Symfony | Redirect `/users` → `/users/` (configurable per route) | ✅ |
| Laravel | Treats as different routes; middleware can normalize | ✅ |
| FastRoute | Treats as different routes | ❌ |
| **KaririCode V4.0** | Treats as different routes | ❌ |

### Missing Fallback Route

When no route matches, `RouteNotFoundException` is thrown. There is no mechanism to register a default handler (404 page, API error response) without wrapping the `match()` call in a try/catch. Laravel's `Route::fallback()` and Symfony's default route provide this.

## Decision

### Trailing Slash Policy

Add a configurable `TrailingSlashPolicy` to `RouteRegistry`:

```php
enum TrailingSlashPolicy
{
    case Strict;     // /users ≠ /users/ (current behavior, default)
    case Normalize;  // Strip trailing slash before matching (/users/ → /users)
    case Redirect;   // Return a redirect result for mismatched trailing slash
}
```

```php
$router = new RouteRegistry(
    trailingSlash: TrailingSlashPolicy::Normalize,
);
```

**Normalize** strips the trailing slash from the incoming URI (except root `/`) before passing to the matcher. This is the recommended setting for APIs.

**Redirect** returns a special `MatchResult` flagged with `isRedirect: true` and the corrected URI. The protocol adapter is responsible for issuing the actual redirect (301 for GET/HEAD, 308 for other methods per RFC 7538).

### Fallback Route

Add `RouteRegistry::fallback()`:

```php
$router->fallback(function (array $context): mixed {
    return ['error' => 'Not Found', 'code' => 404];
});

// Or with a class handler
$router->fallback([NotFoundController::class, 'handle']);
```

**Semantics:** If `match()` would throw `RouteNotFoundException`, it instead returns a `MatchResult` with the fallback handler and empty parameters. The fallback does NOT trigger for `MethodNotAllowedException` (405) — that remains an exception because the URI exists.

**Implementation:** The fallback route is stored as a `?RouteEntry` on `RouteRegistry`. It is never registered in the matchers (it should not appear in `routes()` or affect `allowedMethods()`).

## Consequences

### Positive

- Eliminates the most common "silent 404" debugging headache.
- Configurable per-router — APIs can use `Normalize`, web apps can use `Redirect`.
- Fallback provides clean error handling without try/catch boilerplate.
- `Strict` mode (default) preserves backward compatibility.

### Negative

- `Normalize` mode means `/users/` and `/users` are indistinguishable — if an API intentionally uses both with different semantics, it must use `Strict`.
- `Redirect` mode adds a round-trip for mismatched requests — acceptable for web browsers but suboptimal for API clients.
- Fallback route changes the `match()` return type semantics — callers must check `$result->route->isFallback` (or similar flag) to distinguish real matches from fallbacks.

### Migration

Non-breaking. Default is `Strict` (current behavior). Fallback is opt-in.

## References

- RFC 3986 §3.3 — Path component: trailing slash significance
- RFC 7538 — HTTP 308 Permanent Redirect
- Symfony trailing slash — https://symfony.com/doc/current/routing.html#redirecting-urls-with-a-trailing-slash
