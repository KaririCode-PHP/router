# KaririCode Router

Protocol-agnostic routing for PHP 8.4+. The router resolves a method and a path, both plain strings, to the route that serves them: static paths through a hash map, paths with placeholders through a few combined PCRE expressions per method. Routes come from a fluent API, groups, PHP attributes or route files, and every definition is checked when it is registered. The compiled table can live in an OPcache file or a PSR-16 cache.

Because methods are strings, the same router serves HTTP verbs, gRPC operations (`UNARY`), WebSocket events (`SUBSCRIBE`) or CLI commands (`RUN`). Protocol packages such as kariricode/http provide a `RequestNormalizer` that turns their request into those two strings; the router depends on none of them.

## Installation

```bash
composer require kariricode/router
```

PHP 8.4 or later. The runtime needs only kariricode/exception, which brings kariricode/contract.

## Quick start

```php
use KaririCode\Router\Core\RouteRegistry;
use KaririCode\Router\Generator\RouteUrlGenerator;

$router = new RouteRegistry();
$router->get('/users', [UserController::class, 'index'], 'users.index')
    ->get('/users/{id:int}', [UserController::class, 'show'], 'users.show')
    ->post('/users', [UserController::class, 'store'], 'users.store');

$result = $router->match('GET', '/users/42');
$result->handler();                  // [UserController::class, 'show']
$result->parameters->getInt('id');   // 42
$result->routeName();                // 'users.show'

(new RouteUrlGenerator($router))->generate('users.show', ['id' => 42]); // '/users/42'
```

The handler is a definition: the router never calls it. Your dispatcher resolves `'Controller::action'`, `[Controller::class, 'action']`, a service id or a Closure.

## Defining routes

### Fluent API and any protocol

```php
$router->get('/health', 'HealthCheck');                 // GET and HEAD
$router->put('/users/{id:int}', 'UserUpdate', 'users.update', middleware: ['auth']);
$router->route(['UNARY'], '/greeter.Greeter/{method}', 'GrpcGateway', 'grpc');
$router->route(['GET'], '/reports/{format}', 'Reports', 'reports', defaults: ['format' => 'pdf'], metadata: ['cache' => 60]);
```

### Groups

```php
use KaririCode\Router\Core\RouteGroup;

$router->group('/api/v1', static function (RouteGroup $api): void {
    $api->get('/users', 'UserIndex', 'users');

    $api->group('/admin', static function (RouteGroup $admin): void {
        $admin->get('/stats', 'AdminStats', 'stats');   // GET /api/v1/admin/stats, named api.admin.stats
    }, middleware: ['auth.admin'], namePrefix: 'admin.');
}, middleware: ['auth.api'], namePrefix: 'api.');
```

Middleware runs outer group first, then inner group, then the route's own.

### Attributes

```php
use KaririCode\Router\Attribute\Controller;
use KaririCode\Router\Attribute\Route;
use KaririCode\Router\Core\AttributeRouteLoader;

#[Controller('/api/v1/posts', middleware: ['auth'], namePrefix: 'posts.')]
final class PostController
{
    #[Route('/{id:int}', method: ['GET', 'HEAD'], name: 'show')]
    public function show(): void {}

    #[Route('/', method: 'POST', name: 'store')]
    public function store(): void {}
}

foreach ((new AttributeRouteLoader())->loadFromClass(PostController::class) as $route) {
    $router->addRoute($route);
}
```

The prefix and the pattern are joined as written: `#[Route('/')]` under `/api/v1/posts` is `/api/v1/posts/`.

### Route files

```php
// config/routes.php
return [
    ['GET', '/health', 'HealthCheck', 'health'],
    ['GET|POST', '/search', 'Search', 'search', 'middleware' => ['throttle'], 'defaults' => ['page' => '1']],
    ['group' => '/admin', 'middleware' => ['auth'], 'name_prefix' => 'admin.', 'routes' => [
        ['GET', '/stats', 'AdminStats', 'stats'],
    ]],
];
```

```php
use KaririCode\Router\Core\ArrayRouteLoader;

foreach ((new ArrayRouteLoader())->loadFile(__DIR__ . '/config/routes.php') as $route) {
    $router->addRoute($route);
}
```

## Pattern syntax

| Placeholder | Matches |
|---|---|
| `{name}` | one path segment (no "/", no control characters) |
| `{id:int}` | decimal digits (`integer` is an alias) |
| `{slug:slug}` | lowercase words joined by "-" |
| `{id:uuid}` | 8-4-4-4-12 hexadecimal, any case |
| `{path:any}` | the rest of the path, "/" included |

Patterns match the raw path, without percent-decoding. A mistake fails when the route is defined, never on a request: `{id:\d+}`, `{user-id}`, a stray brace, a repeated name, a pattern without a leading "/", a second route for the same method and pattern, or a second route with the same name all throw `RouteCompilationException`.

A static route beats a pattern that also matches its path, whatever the order: `/users/new` wins over `/users/{id}`. Among patterns of one method, the first registered wins.

## URL generation

```php
$generator = new RouteUrlGenerator($router);
$generator->generate('users.show', ['id' => 42, 'tab' => 'posts']);   // '/users/42?tab=posts'
$generator->generate('files.show', ['path' => 'docs/2026/report final.pdf']);
// '/files/docs/2026/report%20final.pdf'
$generator->withBase('https://api.example.com')->generate('users.index', [], ['page' => '2']);
// 'https://api.example.com/users?page=2'
```

## Caching the compiled table

```php
use KaririCode\Router\Cache\PhpFileStore;
use KaririCode\Router\Matcher\CachedMatcher;
use KaririCode\Router\Matcher\CompositeRouteMatcher;

$matcher = new CachedMatcher(new CompositeRouteMatcher(), new PhpFileStore(__DIR__ . '/var/cache/routes.php'));
$router = new RouteRegistry($matcher);

if (!$matcher->isCached()) {
    require __DIR__ . '/config/routes.php'; // registers on $router
    $matcher->warm();                        // usually a deploy step
}
```

With OPcache on, the table file is read from shared memory. `Integration\SimpleCacheStore` keeps the same table in any PSR-16 cache (`composer require psr/simple-cache` plus an implementation). A table written for other routes is ignored; Closure handlers cannot be cached.

## Observing matches

```php
use KaririCode\Router\Core\MatchStatistics;
use KaririCode\Router\Core\ObservedRouter;
use KaririCode\Router\Integration\EventDispatcherBridge;

$statistics = new MatchStatistics();
$observed = new ObservedRouter($router, $statistics);
$observed->match('GET', '/users/42');
$statistics->averageDurationNs();

$evented = new ObservedRouter($router, new EventDispatcherBridge($psr14Dispatcher)); // RouteMatched, RouteMissed events
```

Observers get the method, the result or the failure and the duration, never the path.

## Middleware

```php
use KaririCode\Router\Middleware\MiddlewarePipeline;

$response = (new MiddlewarePipeline())->execute($middlewareInstances, $handler, $context);
```

`Contract\Middleware` works on a context array, so the same middleware serves every protocol. PSR-15 middleware stays in kariricode/http.

## Errors

Every exception extends a family of kariricode/exception, so an edge can map it without knowing the router. Codes are in block 5000–5099 of the ecosystem registry, prefix RO; messages never repeat the client's method, path or values.

| Exception | Family | Codes | When |
|---|---|---|---|
| `RouteNotFoundException` | NotFound | 5010, 5021 | no route for the path; no route with that name |
| `MethodNotAllowedException` | NotFound | 5011 | the path exists for other methods; `$e->allowedMethods` feeds the Allow header |
| `UrlGenerationException` | Validation | 5020, 5022 | missing parameter; value breaks its type |
| `RouteCompilationException` | Configuration | 5000–5006, 5040–5042 | invalid route definitions; what the cache cannot hold |
| `RouteFileException` | Configuration | 5050–5052 | route file missing, not an array, or an invalid entry |
| `RouteCacheException` | Runtime | 5045–5046 | the cache directory or store refused the write |

`RouteEntry::$host` and `$scheme` are carried to your dispatcher; no matcher reads them yet (ADR-005), so do not rely on them for access control.

## Development

The quality toolchain is installed by [KaririCode Devkit](https://github.com/KaririCode-PHP/devkit) (`kcode`) at the versions `devkit.php` pins, and runs with the configs at the root of this repository.

```bash
# kcode 1.2.1, checked against its published SHA-256
curl -fsSLo kcode.phar https://github.com/KaririCode-PHP/devkit/releases/download/v1.2.1/kcode.phar
echo "1bc43b06798b4e7fc1df341cc505f316549f0dd5efb9c8d80031415b0cdf3e3b  kcode.phar" | sha256sum -c -
sudo install -m 0755 kcode.phar /usr/local/bin/kcode

composer install   # runtime: kariricode/exception, kariricode/contract
kcode init         # toolchain into .kcode/
composer check     # style, PHPStan max, Psalm 1, complexity, the three suites, mutation
composer security  # audit of the runtime and the toolchain, secret scan
```

| Command | Gate |
|---|---|
| `composer test` | unit, integration and conformance suites |
| `composer test-conformance` | KaririCode Spec V4.3.3 §29.2 rules |
| `composer phpstan` / `composer psalm` | level max / errorLevel 1 |
| `composer complexity` | §13 budgets |
| `composer mutation` | Infection, MSI ≥ 80% |
| `composer cs-check` / `composer cs-fix` | PER Coding Style |
| `composer sbom` | CycloneDX 1.5 SBOM |

## Documentation

- [SPEC-001: router architecture](docs/spec/SPEC-001-router-architecture.md)
- [Quality report against Spec V4.3.3](docs/quality/QD-V433-COMPLIANCE.md)
- ADRs: [001 composite matching](docs/adr/ADR-001-composite-matching-strategy.md), [002 protocol-agnostic design](docs/adr/ADR-002-protocol-agnostic-design.md), [003 route cache](docs/adr/ADR-003-opcache-route-caching.md), [004 optional parameters (proposed)](docs/adr/ADR-004-optional-params-custom-constraints.md), [005 host and scheme (proposed)](docs/adr/ADR-005-host-scheme-matching.md), [006 trailing slash and fallback (proposed)](docs/adr/ADR-006-trailing-slash-and-fallback.md), [007 exceptions and codes](docs/adr/ADR-007-exception-families-and-code-block.md), [008 toolchain](docs/adr/ADR-008-toolchain-through-kcode.md), [009 observers and PSR integration](docs/adr/ADR-009-observers-and-psr-integration.md)
- [Upgrading from the 4.0 code](UPGRADE.md)

## License

MIT. See [LICENSE](LICENSE).
