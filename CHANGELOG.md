# Changelog

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
