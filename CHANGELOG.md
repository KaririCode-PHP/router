# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [4.1.0] — 2026-11-27

The version kariricode/http, sse and websocket require. 4.0.0 below was never tagged; the code imported from it did not load (a `callable` property) and its regex tier never matched a route, so this release is a rework more than an increment. [UPGRADE.md](UPGRADE.md) maps every change of API.

### Added

- Protocol-agnostic core of V4.1: methods are plain strings everywhere (`RouteRegistry::route()`, `RouteGroup::map()`, `#[Route(method: 'SUBSCRIBE')]`); `Contract\RequestNormalizer` and `Core\NormalizedRequest` for protocol packages (ADR-002).
- Exceptions on the kariricode/exception 2.0 family bases, with `Exception\RouterCode` in block 5000–5099, prefix RO (ADR-007): `RouteFileException` and `RouteCacheException` join the four existing classes; `RouteNotFoundException::named()` covers unknown route names.
- Definitions fail when they are defined: malformed placeholders, repeated parameter names, unknown types, invalid or empty methods, empty handlers, patterns without a leading "/", a second route for the same method and pattern, and a second route with the same name.
- `Contract\RouteCacheStore` with `Cache\PhpFileStore` (atomic OPcache file) and `Integration\SimpleCacheStore` (PSR-16) for `CachedMatcher`; the table carries a format version and a checksum, and a table built for other routes is ignored (ADR-003, ADR-009).
- `Contract\MatchObserver`, `Core\ObservedRouter` and `Core\MatchStatistics`; `Integration\EventDispatcherBridge` dispatches `Event\RouteMatched` and `Event\RouteMissed` to a PSR-14 dispatcher (ADR-009).
- `Core\ArrayRouteLoader`, which checks every entry of a route array or file and reports the position of the first bad one.
- `RouteGroup::options()`, `any()`, and defaults and metadata in `RouteGroup::map()`; `RouteEntry::withNamePrefix()` and `parametersFrom()`.
- Quality toolchain through KaririCode Devkit with the root configs (ADR-008): PHPStan max, Psalm 1, PER-CS, §13 budgets, unit, integration and conformance suites, Infection, secret scan, SBOM, and CI on PHP 8.4 and 8.5.
- ADR-007, ADR-008, ADR-009; amendments to ADR-001, ADR-002 and ADR-003; SPEC-001 rewritten for 4.1; quality report `docs/quality/QD-V433-COMPLIANCE.md`.

### Changed

- RegexMatcher builds each chunk as a branch-reset group with MARK verbs and positional captures, 30 routes per chunk (ADR-001, Amendment).
- Error codes moved from 5100–5159 to 5000–5099 by a constant −100, keeping the layout; distinct failures now have distinct codes. Messages no longer contain the client's method, path or values.
- Expressions are anchored with `\A` and `\z`; the `string` and `any` types reject control characters; `uuid` accepts both letter cases.
- `CompositeRouteMatcher::allowedMethods()` joins the static and pattern tiers.
- `RouteUrlGenerator` is immutable; `withBase()` returns a new generator.
- `RouteParameters::getInt()` returns the default for values that are not decimal integers or do not fit an int.
- Requires PHP ^8.4 and kariricode/exception ^2.0; PSR-14 and PSR-16 are suggestions for the two classes in `src/Integration`.

### Fixed

- `RouteEntry` could not be loaded: it declared a `callable` property, which PHP forbids. The handler is now `string|array|Closure`.
- No parameterized route ever matched: the regex tier skipped the empty mark group it looked for (code review BUG-003), and two routes with the same parameter name in one chunk made PCRE reject the expression.
- `CacheBridge` cached nothing (BUG-002); replaced by `SimpleCacheStore`.
- `{path:any}` values lost their "/" separators in generated URLs (BUG-005).
- A static route's defaults never reached the match result.
- A trailing newline matched a route.
- BUG-004 of the review was a test that named its route `admin.stats` inside a group prefixed `admin.`; the behaviour was right and is now documented.

### Removed

- `RouterException`, `Integration\CacheBridge`, `Integration\ConfiguratorBridge` (now `Core\ArrayRouteLoader`), `Integration\ObservabilityBridge` (now `Core\MatchStatistics`).
- `CompositeRouteMatcher::getStaticMatcher()` and `getRegexMatcher()` (public readonly properties instead), `RoutePattern::reverseUri()`, `RouteParameterType::fromString()` (now `tryFromString()`), `HttpMethod::fromString()` and `label()`.
- The unused `host` and `scheme` parameters of the `RouteGroup` constructor, and `#[Route]` on classes, which the loader never read.

### Deprecated

- `Enum\HttpMethod`: pass method strings. Removed in 5.0.

## [4.0.0] - 2026-03-01

### Changed — Breaking
- Minimum PHP version: 8.4 (was 8.3)
- Architecture: ARFA 1.3 compliance (was 1.2)
- Specification: Quality Directive V4.0 (was V3.2)
- All value objects are `final readonly class` (was mutable)
- Enums are pure (was backed)
- Contracts accept raw strings instead of PSR-7 objects (protocol agnostic)

### Added
- Two-tier CompositeRouteMatcher (StaticMatcher + RegexMatcher)
- Typed parameter constraints: `{id:int}`, `{slug:slug}`, `{uuid:uuid}`, `{path:any}`
- PHP 8.4 `#[Route]` and `#[Controller]` attributes
- AttributeRouteLoader for reflection-based route discovery
- RouteUrlGenerator with constraint validation
- MiddlewarePipeline (protocol-agnostic onion model)
- RouteGroup with nested prefix/middleware/name inheritance
- CachedMatcher with OPcache file-backed caching
- Integration bridges: Cache, EventDispatcher, Configurator, Observability
- Comprehensive exception hierarchy with allocated error code range 5100–5159
- ADR documentation (3 records)
- SPEC-001 architecture specification

### Removed
- PSR-7 coupling from core contracts
- Backed enum values
- `toArray()` / `fromArray()` antipatterns
- Traditional getter methods (replaced by readonly properties)

### Fixed
- Group prefix concatenation now normalizes trailing slashes
- HEAD method auto-registered alongside GET
- URI normalization ensures leading slash
