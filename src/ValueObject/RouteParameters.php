<?php

declare(strict_types=1);

namespace KaririCode\Router\ValueObject;

/**
 * Immutable bag of extracted route parameters (ARFA 1.3 P1).
 *
 * Spec V4.0: final readonly class implementing \Countable.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc3986#section-3.3 URI path parameters
 */
final readonly class RouteParameters implements \Countable
{
    /** @var array<string, string> */
    private array $items;

    /**
     * @param array<string, string> $items
     */
    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    public function get(string $name, ?string $default = null): ?string
    {
        return $this->items[$name] ?? $default;
    }

    public function getInt(string $name, ?int $default = null): ?int
    {
        $value = $this->items[$name] ?? null;
        return $value !== null ? (int) $value : $default;
    }

    public function has(string $name): bool
    {
        return isset($this->items[$name]);
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * @param array<string, string> $extra
     */
    public function merge(array $extra): self
    {
        return new self([...$this->items, ...$extra]);
    }
}
