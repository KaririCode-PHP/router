<?php

declare(strict_types=1);

namespace KaririCode\Router\Tests\Fixture;

use DateInterval;
use Psr\SimpleCache\CacheInterface;

/** A PSR-16 cache in memory; $accept decides whether set() and delete() succeed. */
final class InMemorySimpleCache implements CacheInterface
{
    /** @var array<string, mixed> */
    public array $values = [];

    /** @var array<string, DateInterval|int|null> */
    public array $ttls = [];

    public function __construct(
        public bool $accept = true,
    ) {}

    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
    }

    #[\Override]
    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        if ($this->accept) {
            $this->values[$key] = $value;
            $this->ttls[$key] = $ttl;
        }

        return $this->accept;
    }

    #[\Override]
    public function delete(string $key): bool
    {
        if ($this->accept) {
            unset($this->values[$key]);
        }

        return $this->accept;
    }

    #[\Override]
    public function clear(): bool
    {
        $this->values = [];

        return true;
    }

    /**
     * @param iterable<string> $keys
     *
     * @return iterable<string, mixed>
     */
    #[\Override]
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $values = [];

        foreach ($keys as $key) {
            $values[$key] = $this->get($key, $default);
        }

        return $values;
    }

    /**
     * @param iterable<string, mixed> $values
     */
    #[\Override]
    public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }

        return $this->accept;
    }

    /**
     * @param iterable<string> $keys
     */
    #[\Override]
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return $this->accept;
    }

    #[\Override]
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }
}
