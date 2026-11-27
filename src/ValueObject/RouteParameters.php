<?php

declare(strict_types=1);

namespace KaririCode\Router\ValueObject;

use Countable;

/**
 * The parameters a match extracted from the path, merged with the route's
 * defaults. Values stay strings, exactly as they appeared in the path:
 * converting them is the handler's decision, and getInt() covers the
 * common case of an {id:int} placeholder.
 *
 * Preconditions: values are not percent-decoded; the router matches the raw
 * path (RFC 3986 §2.1), so a handler that needs the decoded text calls
 * rawurldecode() itself.
 *
 * Thread-safety: immutable; merge() returns a new instance.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     $result = $router->match('GET', '/users/42');
 *     $result->parameters->get('id');      // '42'
 *     $result->parameters->getInt('id');   // 42
 *     $result->parameters->has('format');  // false
 *
 * References: RFC 3986 §2.1 (percent-encoding) and §3.3; SPEC-001 §4.
 *
 * @see https://www.rfc-editor.org/rfc/rfc3986#section-2.1
 */
final readonly class RouteParameters implements Countable
{
    /**
     * @param array<string, string> $items
     */
    public function __construct(
        private array $items = [],
    ) {}

    public function get(string $name, ?string $default = null): ?string
    {
        return $this->items[$name] ?? $default;
    }

    /**
     * The parameter as an integer, or $default when it is missing or is not
     * a decimal integer that fits PHP_INT_MAX.
     */
    public function getInt(string $name, ?int $default = null): ?int
    {
        $value = filter_var($this->items[$name] ?? null, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);

        return is_int($value) ? $value : $default;
    }

    public function has(string $name): bool
    {
        return isset($this->items[$name]);
    }

    /**
     * Every parameter, for code that hands them on as a map (request
     * attributes, a view model).
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->items;
    }

    #[\Override]
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * A copy with $extra added; on a shared name, $extra wins.
     *
     * @param array<string, string> $extra
     */
    public function merge(array $extra): self
    {
        return new self([...$this->items, ...$extra]);
    }
}
