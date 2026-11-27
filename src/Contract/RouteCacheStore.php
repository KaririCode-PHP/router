<?php

declare(strict_types=1);

namespace KaririCode\Router\Contract;

use KaririCode\Router\Exception\RouteCacheException;

/**
 * Where CachedMatcher keeps its compiled route table between processes: a
 * PHP file OPcache serves from shared memory (PhpFileStore) or a PSR-16
 * cache (SimpleCacheStore). The table is a nested array of scalars, so any
 * store that can keep arrays can implement this.
 *
 * Preconditions: load() returns exactly what the last save() received, or
 * null when nothing is stored or the stored data is unreadable; it never
 * throws for a miss. CachedMatcher checks the table's format and checksum,
 * so a store does not.
 *
 * Thread-safety: save() must replace the table atomically, so a concurrent
 * load() sees the old table or the new one, never a mix.
 *
 * Side effects: save() and clear() write to the store.
 *
 * Example:
 *
 *     $matcher = new CachedMatcher(new CompositeRouteMatcher(), new PhpFileStore('/var/cache/routes.php'));
 *     $matcher->warm(); // calls save() with the compiled table
 *
 * References: ADR-003 (route cache); ADR-009; PSR-16.
 *
 * @see https://www.php-fig.org/psr/psr-16/
 */
interface RouteCacheStore
{
    /**
     * @return array<array-key, mixed>|null
     */
    public function load(): ?array;

    /**
     * @param array<string, mixed> $table
     *
     * @throws RouteCacheException when the store refuses the write
     */
    public function save(array $table): void;

    /**
     * @throws RouteCacheException when the store refuses the removal
     */
    public function clear(): void;
}
