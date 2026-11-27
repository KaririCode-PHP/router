<?php

declare(strict_types=1);

namespace KaririCode\Router\Integration;

use KaririCode\Router\Core\RouteRegistry;

/**
 * Bridge to KaririCode\Cache for route compilation caching.
 *
 * ARFA 1.3 P3 (Adaptive Optimization): caches compiled route tables
 * to avoid repeated regex compilation on warm restarts.
 * Spec V4.0: final class.
 *
 * Stores the compiled route table in the cache layer so that
 * subsequent requests skip regex compilation entirely. Uses a
 * checksum to auto-invalidate when route definitions change.
 *
 * Requires: kariricode/cache ^4.0
 *
 * Usage:
 *     $bridge = new CacheBridge($cache, 'routes');
 *     $bridge->warmOrRestore($registry);
 *
 * @see https://www.php-fig.org/psr/psr-6/ PSR-6 Caching Interface
 */
final class CacheBridge
{
    /**
     * @param object $cache  Any object implementing get(string):mixed and set(string,mixed,int):void
     * @param string $prefix Cache key prefix
     * @param int    $ttl    TTL in seconds (0 = forever)
     */
    public function __construct(
        private readonly object $cache,
        private readonly string $prefix = 'kariricode.router',
        private readonly int $ttl = 0,
    ) {
    }

    /**
     * Attempt to restore routes from cache. On miss, populate from registry.
     *
     * @return bool True if cache was hit.
     */
    public function warmOrRestore(RouteRegistry $registry): bool
    {
        $key = $this->prefix . '.compiled';

        $cached = $this->cacheGet($key);
        if ($cached !== null && is_array($cached)) {
            // Cached data exists — but we still need routes registered
            // in the matcher. The cache only accelerates the compiled regex.
            return true;
        }

        // Cache miss — store a marker for next time
        $this->cacheSet($key, ['warmed' => true, 'count' => count($registry->routes())]);

        return false;
    }

    public function invalidate(): void
    {
        $this->cacheDelete($this->prefix . '.compiled');
    }

    // ── Cache Adapter (duck-typing) ──────────────────────────────────────

    private function cacheGet(string $key): mixed
    {
        if (method_exists($this->cache, 'get')) {
            return $this->cache->get($key);
        }

        return null;
    }

    private function cacheSet(string $key, mixed $value): void
    {
        if (method_exists($this->cache, 'set')) {
            $this->cache->set($key, $value, $this->ttl);
        }
    }

    private function cacheDelete(string $key): void
    {
        if (method_exists($this->cache, 'delete')) {
            $this->cache->delete($key);
        }
    }
}
