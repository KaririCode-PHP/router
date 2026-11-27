# Upgrading from the 4.0 code

4.0 was never released; this note is for code written against the 4.0 tarball or its README, such as the 4.0 versions of kariricode/http, sse and websocket, which require router ^4.1.

## Exceptions

| 4.0 | 4.1 |
|---|---|
| `new RouteNotFoundException($method, $uri)` | `RouteNotFoundException::forRequest()` |
| `new MethodNotAllowedException($method, $uri, $allowed)` | `MethodNotAllowedException::allowing($allowed)`; `$e->allowedMethods` is unchanged |
| `UrlGenerationException::unknownRoute($name)` | `RouteNotFoundException::named($name)` (NotFound family) |
| `RouterException` | gone; catch a kariricode/contract family (`NotFound`, `Validation`, `Configuration`, `Runtime`) or the concrete class |
| codes 5100–5159 | 5000–5099, prefix RO (ADR-007 lists every code) |

Messages no longer contain the method, path or values the client sent.

## Classes

| 4.0 | 4.1 |
|---|---|
| `Integration\ConfiguratorBridge::loadInto($registry, $definitions)` | `foreach ((new Core\ArrayRouteLoader())->load($definitions) as $route) { $registry->addRoute($route); }` |
| `Integration\ConfiguratorBridge::loadFile($registry, $path)` | `Core\ArrayRouteLoader::loadFile($path)`, same loop |
| `Integration\CacheBridge` | `CachedMatcher` with `Integration\SimpleCacheStore` (PSR-16) or `Cache\PhpFileStore` |
| `new CachedMatcher($inner, $path)` | `new CachedMatcher($inner, new PhpFileStore($path))` |
| `Integration\EventDispatcherBridge::routeMatched($result, $ms)` | `new ObservedRouter($router, new EventDispatcherBridge($psr14))`: events are dispatched for you |
| `Integration\ObservabilityBridge` | `Core\MatchStatistics` (an observer for `ObservedRouter`); `snapshot()` became the `matches`, `notFound`, `methodNotAllowed` and `totalDurationNs` properties and `averageDurationNs()` |
| `CompositeRouteMatcher::getStaticMatcher()`, `getRegexMatcher()` | the `staticMatcher` and `regexMatcher` properties |
| `RouteParameterType::fromString()` (unknown types became String) | `RouteParameterType::tryFromString()` returns null; unknown types fail at registration |
| `RoutePattern::reverseUri()` | `RouteUrlGenerator::generate()` |
| `HttpMethod::fromString()`, `label()` | `HttpMethod::from()`, `->value`; the enum is deprecated |
| `RouteGroup` constructor `host`, `scheme` | removed: nothing could set them |
| `#[Route]` on a class | removed: the loader never read it; use `#[Controller]` |

## Behaviour

- Invalid definitions throw `RouteCompilationException` at registration: malformed or repeated placeholders, unknown types, invalid methods, empty handlers, patterns without a leading "/", a second route for the same method and pattern, a second route with the same name.
- The `string` and `any` types no longer match control characters, and a trailing newline is no longer a match.
- A static route's defaults now reach `MatchResult::$parameters`.
- A 405 lists the methods of both tiers.
- An `{x:any}` value keeps its "/" in generated URLs.
