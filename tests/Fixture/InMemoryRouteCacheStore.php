<?php

declare(strict_types=1);

namespace KaririCode\Router\Tests\Fixture;

use KaririCode\Router\Contract\RouteCacheStore;

/** A RouteCacheStore in memory, counting reads and writes. */
final class InMemoryRouteCacheStore implements RouteCacheStore
{
    /** @var array<array-key, mixed>|null */
    public ?array $stored = null;

    public int $loads = 0;

    public int $saves = 0;

    public int $clears = 0;

    #[\Override]
    public function load(): ?array
    {
        ++$this->loads;

        return $this->stored;
    }

    #[\Override]
    public function save(array $table): void
    {
        ++$this->saves;
        $this->stored = $table;
    }

    #[\Override]
    public function clear(): void
    {
        ++$this->clears;
        $this->stored = null;
    }
}
