<?php

declare(strict_types=1);

namespace KaririCode\Router\Attribute;

use KaririCode\Router\Enum\HttpMethod;

/**
 * Declares a route on a controller method.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): accepts both string literals
 * and the deprecated HttpMethod enum for backward compatibility.
 * String literals are preferred for multi-protocol routing.
 * Spec V4.0: final readonly class with promoted constructor properties.
 *
 * Usage (string — recommended, protocol-agnostic):
 *
 *     #[Route('/users/{id:int}', method: 'GET', name: 'users.show')]
 *     public function show(int $id): Response { ... }
 *
 * Usage (HttpMethod enum — backward compatible):
 *
 *     #[Route('/users/{id:int}', method: HttpMethod::Get, name: 'users.show')]
 *     public function show(int $id): Response { ... }
 *
 * When used on a class, defines defaults inherited by all methods
 * (prefix, middleware, name prefix).
 *
 * @see https://datatracker.ietf.org/doc/html/rfc3986#section-3.3 URI path pattern
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class Route
{
    /** @var list<string> Uppercase method strings. */
    public array $methods;

    /**
     * @param string|null                                     $pattern
     * @param string|HttpMethod|list<string|HttpMethod>       $method     Accepts strings or HttpMethod enum (backward compat)
     * @param list<class-string>                              $middleware
     * @param array<string, string>                           $defaults
     */
    public function __construct(
        public ?string $pattern = null,
        string|HttpMethod|array $method = 'GET',
        public ?string $name = null,
        public array $middleware = [],
        public array $defaults = [],
        public ?string $host = null,
        public ?string $scheme = null,
    ) {
        $raw = is_array($method) ? $method : [$method];

        $this->methods = array_values(array_map(
            static fn (string|HttpMethod $m): string => $m instanceof HttpMethod
                ? strtoupper($m->value)
                : strtoupper($m),
            $raw,
        ));
    }
}
