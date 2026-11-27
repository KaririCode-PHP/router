# ADR-004: Optional Parameters and Custom Regex Constraints

## Status

Proposed — not part of 4.1, which brings the 4.0 code to the Specification first (ADR-007 to ADR-009); retargeted to 4.2

## Date

2026-03-01

## Context

### Gap: Optional Parameters

FastRoute supports optional trailing segments via bracket syntax: `/articles/{id:\d+}[/{title}]`. Laravel supports `{id?}` with route defaults. KaririCode V4.0 has no optional parameter syntax, forcing developers to register duplicate routes:

```php
// Current: 2 routes for 1 logical endpoint
$router->get('/articles/{id:int}', 'ArticleController::show', 'articles.show');
$router->get('/articles/{id:int}/{slug:slug}', 'ArticleController::show', 'articles.show-slug');
```

This causes route count explosion in APIs with optional suffixes (locale, format, pagination).

### Gap: Custom Regex Constraints

KaririCode V4.0 offers 5 predefined types (`int`, `string`, `slug`, `uuid`, `any`). Real-world APIs frequently need:

- Date formats: `{date:20\d{2}-\d{2}-\d{2}}`
- Locale codes: `{locale:[a-z]{2}(-[A-Z]{2})?}`
- Version strings: `{version:v\d+\.\d+}`
- Hex tokens: `{token:[0-9a-f]{64}}`

Without custom constraints, validation falls to the controller layer — losing the router's ability to produce correct 404s for malformed URLs.

## Decision

### Optional Parameter Syntax

Adopt the bracket syntax `[/{segment}]` (FastRoute convention) for optional trailing segments:

```php
// Single optional segment
$router->get('/articles/{id:int}[/{slug:slug}]', handler);
// Matches: /articles/42 AND /articles/42/hello-world

// Multiple optional segments (nested brackets)
$router->get('/archive[/{year:int}[/{month:int}]]', handler);
// Matches: /archive, /archive/2025, /archive/2025/03

// Optional with default
$router->get('/users[/{format}]', handler, defaults: ['format' => 'json']);
```

**Compilation:** The `RoutePattern` compiler expands brackets into regex alternation:

```
/articles/{id:int}[/{slug:slug}]
→ #^/articles/(?P<id>[0-9]+)(?:/(?P<slug>[a-z0-9]+(?:-[a-z0-9]+)*))?$#u
```

**Constraint:** Optional segments must be at the end of the pattern. Nested brackets are allowed but only for suffix chains. `[/opt]/required` is rejected at compilation time with `RouteCompilationException`.

### Custom Regex Constraints

Extend the parameter syntax to accept raw PCRE patterns via the `regex:` prefix:

```php
// Predefined type (unchanged)
$router->get('/users/{id:int}', handler);

// Custom regex
$router->get('/events/{date:regex(\d{4}-\d{2}-\d{2})}', handler);
$router->get('/files/{hash:regex([0-9a-f]{64})}', handler);

// Fluent where() API (alternative)
$router->get('/users/{name}', handler)->where('name', '[a-z]+');
```

**Implementation:** `RouteParameterType::fromString()` checks for the `regex(...)` prefix. If found, returns a new `RouteParameterType::Custom` case that carries the raw PCRE fragment. The fragment is validated at registration time via `preg_match()` to catch syntax errors early.

### where() Fluent API

For cases where inline syntax is awkward, a fluent `where()` method on `RouteEntry` provides per-route constraints:

```php
$router->get('/u/{name}', handler)
    ->where('name', '[a-z][a-z0-9_-]{2,30}');

$router->get('/p/{id}', handler)
    ->where('id', '\d{1,10}');
```

**Note:** `where()` returns a new `RouteEntry` (immutability preserved per ARFA P1). The registry captures the returned entry.

## Pattern Compilation Changes

### Before (V4.0)

```
{name}          → (?P<name>[^/]+)
{name:int}      → (?P<name>[0-9]+)
{name:slug}     → (?P<name>[a-z0-9]+(?:-[a-z0-9]+)*)
```

### After (V4.1)

```
{name}                      → (?P<name>[^/]+)
{name:int}                  → (?P<name>[0-9]+)
{name:slug}                 → (?P<name>[a-z0-9]+(?:-[a-z0-9]+)*)
{name:regex(\d{4}-\d{2})}  → (?P<name>\d{4}-\d{2})
[/{name}]                   → (?:/(?P<name>[^/]+))?
[/{name:int}]               → (?:/(?P<name>[0-9]+))?
```

### URL Generation with Optional Parameters

`RouteUrlGenerator::generate()` omits optional segments when no value is provided and no default exists:

```php
$gen->generate('articles.show', ['id' => 42]);
// → /articles/42 (slug omitted — optional)

$gen->generate('articles.show', ['id' => 42, 'slug' => 'hello']);
// → /articles/42/hello (slug included)
```

## Consequences

### Positive

- Closes the most significant feature gap vs. FastRoute, Symfony, and Laravel.
- Reduces route count for APIs with optional suffixes (locale, format, pagination).
- Custom regex gives power users full PCRE control without sacrificing the predefined type system.
- `where()` fluent API provides Laravel-familiar DX.
- URL generation handles optional segments transparently.

### Negative

- Bracket parsing adds complexity to `RoutePattern` compilation (~30 LOC).
- Custom regex constraints bypass type safety — developers can write invalid PCRE (mitigated by validation at registration time).
- Optional parameters in combined group-regex increase alternation count per chunk (mitigated by chunk size tuning).

### Migration

Non-breaking. Existing patterns without brackets or custom regex continue to work identically. New syntax is purely additive.

## References

- nikic/FastRoute optional segments — https://github.com/nikic/FastRoute#defining-routes
- Laravel optional parameters — https://laravel.com/docs/routing#parameters-optional-parameters
- PCRE pattern syntax — https://www.php.net/manual/en/reference.pcre.pattern.syntax.php

## Note (4.1)

4.1 rejects any placeholder that is not `{name}` or `{name:type}` with a built-in type, so `{date:regex(...)}` and `[/{slug}]` fail when the route is defined instead of becoming literal text. Adding this ADR's syntax later is therefore additive: patterns that compile under 4.1 keep their meaning.

