<?php

declare(strict_types=1);

namespace KaririCode\Router\Core;

use KaririCode\Router\ValueObject\RouteParameters;

/**
 * Immutable result of a successful route match (ARFA 1.3 P1).
 *
 * Spec V4.0: final readonly class with promoted constructor properties.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc3986#section-3.3 URI path parameters
 *
 * Contains everything the dispatcher needs: the matched route entry,
 * extracted URI parameters, and the fully resolved middleware stack.
 */
final readonly class MatchResult
{
    public RouteParameters $parameters;

    /**
     * @param array<string, string>     $rawParameters
     * @param list<string|class-string> $middleware     Merged group + route middleware
     */
    public function __construct(
        public RouteEntry $route,
        array $rawParameters = [],
        public array $middleware = [],
    ) {
        $this->parameters = new RouteParameters($rawParameters);
    }

    public function handler(): string|callable|array
    {
        return $this->route->handler;
    }

    public function routeName(): ?string
    {
        return $this->route->name;
    }
}
