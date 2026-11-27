# ADR-003: OPcache-Based Route Caching

## Status

Accepted — V4.0.0; implemented in 4.1 (see Amendment)

## Date

2026-03-01

## Context

Route compilation (regex building, parameter extraction, chunk grouping) is expensive on cold start. Production deployments must avoid recompilation on every request.

### Compilation Cost Profile

| Operation | Cost (400 routes) | Notes |
|---|---|---|
| RoutePattern construction | ~2ms | PCRE compilation per pattern |
| RegexMatcher chunk compilation | ~5ms | Group-regex assembly, chunk splitting |
| StaticMatcher population | ~0.3ms | Hash-map insertion |
| **Total cold start** | **~7ms** | First request in a new PHP process |
| **Warm match (in-memory)** | **~0.002ms** | Subsequent requests in same process |

With PHP-FPM's process recycling, cold starts occur at process spawn (every N requests or after idle timeout). With OPcache, a cached route file eliminates all compilation cost.

### Options Evaluated

| Option | Cold Start | Warm Match | Dependencies | Read-Only FS |
|---|---|---|---|---|
| **(a) Serialized PHP blob** | ~3ms (unserialize) | ~0.002ms | None | ❌ |
| **(b) OPcache PHP file** | ~0ms (shared mem) | ~0.002ms | OPcache | ❌ |
| **(c) APCu/Redis cache** | ~1ms (network) | ~0.002ms | Extension | ✅ |
| **(d) Compiled PHP class** | ~0ms (shared mem) | ~0.001ms | OPcache + build step | ❌ |

## Decision

Use **OPcache-friendly PHP file caching (option b)** for V4.0, with **compiled PHP class (option d)** as a V4.1+ optimization.

### Target Architecture

```
                      CLI: route:cache
                            │
                            ▼
              ┌──────────────────────────┐
              │  CachedMatcher::warm()   │
              │                          │
              │  1. Build all routes      │
              │  2. Compile regex chunks  │
              │  3. Export static map     │
              │  4. Write PHP file        │
              │  5. Invalidate OPcache    │
              └────────────┬─────────────┘
                           │
                           ▼
              ┌──────────────────────────┐
              │  cache/routes.php        │
              │                          │
              │  return [                │
              │    'static' => [...],    │  ← uri → method → handler
              │    'regex'  => [...],    │  ← method → chunks[regex, routeMap]
              │    'named'  => [...],    │  ← name → pattern
              │    'checksum' => 'sha256'│  ← route definition fingerprint
              │  ];                      │
              └────────────┬─────────────┘
                           │
                           ▼
              ┌──────────────────────────┐
              │  OPcache shared memory   │
              │  (zero deserialization)   │
              └──────────────────────────┘
```

### Cache Invalidation Strategy

- **Checksum-based:** SHA-256 of sorted route definitions. On `match()`, if the live checksum differs from cached, fall back to inner matcher and log a warning.
- **Explicit:** `CachedMatcher::clear()` removes the file and calls `opcache_invalidate()`.
- **CLI-driven:** `route:cache` rebuilds, `route:clear` removes. No auto-rebuild in production.

### Atomic Write Protocol

```
1. Generate temp file: {cachePath}.{random8hex}.tmp
2. Write content with LOCK_EX
3. Rename temp → cachePath (atomic on POSIX)
4. opcache_invalidate(cachePath, force: true)
```

This prevents partial reads during regeneration.

## Current State (V4.0.0)

> **Transparency note:** The current `CachedMatcher::warm()` writes a marker file `['warmed' => true, 'timestamp' => time()]` — it does NOT serialize the compiled regex data. The `match()` method delegates to the inner matcher unconditionally. Full route serialization is tracked as a V4.1 deliverable.

The atomic write protocol and OPcache invalidation are correctly implemented. What's missing is the actual route data serialization in `warm()` and the cache-aware fast path in `match()`.

### V4.1 Implementation Plan

1. Add `StaticMatcher::export(): array` — returns `uri → method → [handler, name, middleware, defaults]`
2. Add `RegexMatcher::exportCompiled(): array` — already exists but returns raw objects (needs serializable form)
3. `CachedMatcher::warm()` — call both exports, serialize to PHP `return` array with checksum
4. `CachedMatcher::match()` — on cache hit, construct `MatchResult` directly from cached array, bypassing inner matcher

## Consequences

### Positive

- Near-zero overhead on cache hit (OPcache serves from shared memory).
- No external dependency (Redis, Memcached) required.
- Works with any hosting environment that has OPcache enabled (default in PHP 8.x).
- Atomic writes prevent partial-read corruption.
- Checksum-based invalidation catches stale caches without manual intervention.

### Negative

- Requires file system write access (not available in read-only containers without volume mounts).
- OPcache invalidation must be triggered explicitly.
- Route handlers that are closures cannot be serialized — only string handlers and `[class, method]` arrays are cacheable.

### Read-Only Container Mitigation

For read-only containers, two approaches:

1. **Build-time caching:** Run `route:cache` during Docker image build, include the cache file in the image.
2. **In-memory only:** Use `CompositeRouteMatcher` without `CachedMatcher`. Lazy compilation amortizes cost over the process lifetime.

## References

- PHP OPcache — https://www.php.net/manual/en/opcache.configuration.php
- POSIX atomic rename — https://man7.org/linux/man-pages/man2/rename.2.html
- ARFA 1.3 — P1 (Immutable State): cached data is read-only after generation

## Amendment (4.1, 2026-11-27)

The plan above is implemented, with three changes:

- **Stores.** `CachedMatcher` keeps the table in a `Contract\RouteCacheStore` (ADR-009). `Cache\PhpFileStore` is the OPcache file of this ADR: temporary file, `rename()`, `opcache_invalidate()`, PHP warnings turned into `RouteCacheException`. `Integration\SimpleCacheStore` keeps the same table in a PSR-16 cache, for read-only containers or hosts without OPcache, which the 4.0 `CacheBridge` promised and never did (code review BUG-002).
- **Freshness.** The table carries a format version and a SHA-256 of every route as written. When the inner matcher has routes, the table is used only if the checksum matches them, so a table left by an earlier deploy is ignored instead of serving old routes. An application that skips registering routes when `isCached()` is true gets the full saving.
- **What cannot be cached** fails at `warm()` with `RouteCompilationException`: a Closure handler, metadata holding objects, or an inner matcher other than `CompositeRouteMatcher` (ADR-007).

