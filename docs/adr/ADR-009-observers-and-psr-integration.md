# ADR-009: Integration through router-owned contracts and PSR interfaces

## Status

Accepted — 4.1

## Date

2026-11-27

## Context

4.0 had four "bridges" in `src/Integration`. Each took `object` and probed it with `method_exists()`:

- `CacheBridge` stored `['warmed' => true, 'count' => N]` and never cached a route (code review BUG-002).
- `EventDispatcherBridge` dispatched `stdClass` events, which no PSR-14 listener can type-hint (ARCH-006).
- `ObservabilityBridge` forwarded to an untyped metrics object; nothing called it, although `RouteRegistry`'s docblock said it emitted events.
- `ConfiguratorBridge` needed no configurator: it read plain PHP arrays, through a second registry.

Duck typing hides the contract from both the analysers and the reader, `object` parameters are a Spec V4.3.3 deviation, and a bridge that names another KaririCode package invites the circular dependency §31 forbids. The router's own V4.1 rule (`tasks/todo.md`) points the other way: packages depend on the router, never the reverse.

## Decision

- **Observation.** `Contract\MatchObserver` (`routeMatched`, `routeMissed`) is owned by the router and vendor-neutral, the pattern of kariricode/processor-pipeline's `PipelineObserver`. `Core\ObservedRouter` decorates any `Router`, times each match with `hrtime()` and notifies the observer. The path is never passed (Spec §23.7). `Core\MatchStatistics` counts in memory; a bridge to an observability backend lives in that backend's package.
- **Events.** `Integration\EventDispatcherBridge` implements `MatchObserver` over a PSR-14 `EventDispatcherInterface` and dispatches `Event\RouteMatched` and `Event\RouteMissed`, two final readonly classes.
- **Cache.** `Contract\RouteCacheStore` (`load`, `save`, `clear`) is where `CachedMatcher` keeps its compiled table. `Cache\PhpFileStore` writes an OPcache-friendly PHP file atomically; `Integration\SimpleCacheStore` keeps the table in any PSR-16 cache, which is what `CacheBridge` promised.
- **Route files.** `Core\ArrayRouteLoader` replaces `ConfiguratorBridge`. Like `AttributeRouteLoader`, it returns routes instead of registering them, and it checks every entry.
- psr/event-dispatcher and psr/simple-cache are `suggest` dependencies; only the two classes in `src/Integration` import them, which the conformance suite checks.

## Alternatives Considered

| Alternative | Why not |
|---|---|
| Keep the duck-typed bridges | untyped contracts, `object` parameters, events no listener can type-hint |
| Depend on kariricode/event-dispatcher, cache or observability | a cycle as soon as one of them depends on the router (§31) |
| Emit events from RouteRegistry itself | every application pays for timing; the decorator keeps it opt-in |
| Pass the path to observers | client data that may carry personal data, sent to telemetry by default |

## Consequences

- Every integration point has a type the analysers check and a test double that implements it.
- `CachedMatcher` works with a file or any PSR-16 cache, and the store is replaceable in tests.
- The four 4.0 bridge classes are gone; 4.0 was never released, so no deployed code breaks.

## References

- KaririCode Spec V4.3.3 §23.7, §29.2, §31; ARFA 1.3 P4, P5.
- PSR-14 (Event Dispatcher), PSR-16 (Simple Cache).
- Code review V4.0: BUG-002, ARCH-006.
