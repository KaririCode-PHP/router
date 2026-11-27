# KaririCode Router

High-performance, protocol-agnostic routing engine for PHP 8.4+ with typed parameter constraints, compiled group-regex matching, attribute-based route definition, and deep KaririCode ecosystem integration.

## Overview

KaririCode Router resolves `(method, uri)` pairs to handlers through a two-tier matching strategy: O(1) hash-map lookup for static routes and compiled group-regex for parameterized routes. The engine is protocol-agnostic (ARFA 1.3 P4), serving HTTP, gRPC, WebSocket, and CLI with the same routing core.

## Features

- **Two-tier matching** — static O(1) + compiled regex, automatically classified at registration
- **Typed parameters** — `{id:int}`, `{slug:slug}`, `{uuid:uuid}`, `{path:any}` with constraint validation
- **PHP 8.4 attributes** — `#[Route]`, `#[Controller]` for zero-config route definition
- **Route groups** — nested prefixes, middleware inheritance, name prefixes
- **URL generation** — reverse routing with constraint validation and query string support
- **OPcache file caching** — zero-overhead production matching after warm-up
- **Protocol agnostic** — raw `(string, string)` interface, no PSR-7 coupling
- **Middleware pipeline** — onion-model with protocol-agnostic context
- **Ecosystem bridges** — Cache, EventDispatcher, Configurator, Observability
- **Zero external deps** — only `kariricode/contract` required

## Installation

```bash
composer require kariricode/router
```

## Quick Start

```php
use KaririCode\Router\Core\RouteRegistry;
use KaririCode\Router\Generator\RouteUrlGenerator;

$router = new RouteRegistry();

$router->get('/users', 'UserController::index', 'users.index');
$router->get('/users/{id:int}', 'UserController::show', 'users.show');
$router->post('/users', 'UserController::store', 'users.store');

$result = $router->match('GET', '/users/42');
$result->handler();              // 'UserController::show'
$result->parameters->get('id');  // '42'
$result->routeName();            // 'users.show'

// URL generation
$gen = new RouteUrlGenerator($router);
$gen->generate('users.show', ['id' => 42]);  // '/users/42'
```

## Route Definition

### Fluent API

```php
$router->get('/path', handler, name, middleware);
$router->post('/path', handler, name, middleware);
$router->put('/path', handler, name, middleware);
$router->patch('/path', handler, name, middleware);
$router->delete('/path', handler, name, middleware);
```

### Groups

```php
$router->group('/api/v1', function ($group): void {
    $group->get('/users', 'UserController::index', 'users.index');

    $group->group('/admin', function ($admin): void {
        $admin->get('/stats', 'AdminController::stats', 'stats');
    }, middleware: ['AdminAuth'], namePrefix: 'admin.');
}, middleware: ['ApiAuth'], namePrefix: 'api.');
```

### PHP 8.4 Attributes

```php
use KaririCode\Router\Attribute\{Controller, Route};
use KaririCode\Router\Enum\HttpMethod;

#[Controller('/api/v1/posts', middleware: ['Auth'], namePrefix: 'posts.')]
class PostController
{
    #[Route('/', method: HttpMethod::Get, name: 'index')]
    public function index(): void { }

    #[Route('/{id:int}', method: HttpMethod::Get, name: 'show')]
    public function show(int $id): void { }

    #[Route('/', method: HttpMethod::Post, name: 'store')]
    public function store(): void { }
}

// Load from attributes
$loader = new AttributeRouteLoader();
foreach ($loader->loadFromClass(PostController::class) as $route) {
    $router->addRoute($route);
}
```

## Parameter Types

| Type   | Syntax          | Pattern                          | Example            |
|--------|-----------------|----------------------------------|--------------------|
| string | `{name}`        | `[^/]+`                          | `/users/john`      |
| int    | `{id:int}`      | `[0-9]+`                         | `/users/42`        |
| slug   | `{slug:slug}`   | `[a-z0-9]+(?:-[a-z0-9]+)*`      | `/posts/hello-world` |
| uuid   | `{uuid:uuid}`   | UUID v4 pattern                  | `/items/550e8400-...` |
| any    | `{path:any}`    | `.+` (greedy)                    | `/files/a/b/c.txt` |

## Architecture

The matching pipeline:

1. **RouteRegistry** receives `match(method, uri)`
2. **CompositeRouteMatcher** tries **StaticMatcher** (O(1) hash) first
3. On miss, falls through to **RegexMatcher** (compiled group-regex)
4. Returns immutable **MatchResult** or throws 404/405

## Ecosystem Integration

```php
// Cache bridge — OPcache file caching
$cache = new CacheBridge($cacheInstance, 'routes');
$cache->warmOrRestore($router);

// Event bridge — route lifecycle events
$events = new EventDispatcherBridge($dispatcher);
$events->routeMatched($result, $durationMs);

// Config bridge — load routes from PHP array files
$config = new ConfiguratorBridge();
$config->loadFile($router, 'config/routes.php');

// Observability — metrics and tracing
$obs = new ObservabilityBridge($metricsCollector);
$obs->recordMatch($result, $durationMs);
```

## Error Handling

| Exception                   | Code | When                       |
|-----------------------------|------|----------------------------|
| RouteNotFoundException      | 5110 | No route matches           |
| MethodNotAllowedException   | 5111 | URI exists, wrong method   |
| UrlGenerationException      | 5120 | Missing param, constraint  |
| RouteCompilationException   | 5140 | Cache write failure        |

`MethodNotAllowedException` exposes `$e->allowedMethods` for the `Allow` header.

## Testing

```bash
./vendor/bin/phpunit
```

## Documentation

| Document | Location |
|----------|----------|
| ADR-001: Composite Matching | `docs/adr/ADR-001-composite-matching-strategy.md` |
| ADR-002: Protocol Agnostic  | `docs/adr/ADR-002-protocol-agnostic-design.md` |
| ADR-003: OPcache Caching    | `docs/adr/ADR-003-opcache-route-caching.md` |
| ADR-004: Optional Params    | `docs/adr/ADR-004-optional-params-custom-constraints.md` |
| ADR-005: Host Matching      | `docs/adr/ADR-005-host-scheme-matching.md` |
| ADR-006: Trailing Slash     | `docs/adr/ADR-006-trailing-slash-and-fallback.md` |
| SPEC-001: Architecture      | `docs/spec/SPEC-001-router-architecture.md` |

## License

MIT License. See [LICENSE](LICENSE) for details.
