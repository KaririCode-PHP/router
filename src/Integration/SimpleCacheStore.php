<?php

declare(strict_types=1);

namespace KaririCode\Router\Integration;

use KaririCode\Router\Contract\RouteCacheStore;
use KaririCode\Router\Exception\RouteCacheException;
use KaririCode\Router\Exception\RouteCompilationException;
use Psr\SimpleCache\CacheInterface;

/**
 * Keeps the compiled route table in any PSR-16 cache (APCu, Redis,
 * Memcached), for hosts where the application cannot write PHP files or
 * OPcache is off. It replaces 4.0's CacheBridge, which stored only a
 * marker and never cached a route (code review BUG-002).
 *
 * Preconditions: install a psr/simple-cache implementation; this class is
 * the only one that needs it. The key must use the characters every PSR-16
 * cache accepts (A-Z, a-z, 0-9, "_", ".", up to 64), checked at
 * construction so a bad key fails at boot. A null TTL keeps the table until
 * clear() or the cache's own eviction.
 *
 * Thread-safety: PSR-16 set() replaces a value as a whole; the cache owns
 * its concurrency.
 *
 * Side effects: save() and clear() write to the cache.
 *
 * Example:
 *
 *     $store = new SimpleCacheStore($psr16Cache, 'app.routes');
 *     $matcher = new CachedMatcher(new CompositeRouteMatcher(), $store);
 *
 * References: PSR-16 §1.2 (keys); ADR-003; ADR-009; KaririCode Spec V4.3.3
 * §8.4 (the PSR boundary).
 *
 * @see https://www.php-fig.org/psr/psr-16/
 */
final readonly class SimpleCacheStore implements RouteCacheStore
{
    public function __construct(
        private CacheInterface $cache,
        private string $key = 'kariricode.router.table',
        private ?int $ttl = null,
    ) {
        if (preg_match('/\A[A-Za-z0-9_.]{1,64}\z/', $key) !== 1) {
            throw RouteCompilationException::invalidCacheKey();
        }
    }

    #[\Override]
    public function load(): ?array
    {
        return self::tableOrNull($this->cache->get($this->key));
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function tableOrNull(mixed $data): ?array
    {
        return is_array($data) ? $data : null;
    }

    #[\Override]
    public function save(array $table): void
    {
        if (!$this->cache->set($this->key, $table, $this->ttl)) {
            throw RouteCacheException::writeFailed('PSR-16 key ' . $this->key);
        }
    }

    #[\Override]
    public function clear(): void
    {
        if (!$this->cache->delete($this->key)) {
            throw RouteCacheException::writeFailed('PSR-16 key ' . $this->key);
        }
    }
}
