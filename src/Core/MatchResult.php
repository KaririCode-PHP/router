<?php

declare(strict_types=1);

namespace KaririCode\Router\Core;

use Closure;
use KaririCode\Router\ValueObject\RouteParameters;

/**
 * What a successful match hands the dispatcher: the route, the parameters
 * taken from the path with the route's defaults, and the middleware to run
 * around the handler, outermost first.
 *
 * Preconditions: built by a matcher; $middleware is the route's own list,
 * group middleware already prepended when the route was registered.
 *
 * Thread-safety: immutable.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     $result = $router->match('GET', '/users/42');
 *     $handler = $resolver->resolve($result->handler());
 *     $response = $pipeline->execute($middleware, $handler, ['id' => $result->parameters->getInt('id')]);
 *
 * References: SPEC-001 §4 (match result); ADR-001.
 *
 * @see https://www.rfc-editor.org/rfc/rfc3986#section-3.3
 */
final readonly class MatchResult
{
    public RouteParameters $parameters;

    /**
     * @param array<string, string> $rawParameters
     * @param list<string>          $middleware
     */
    public function __construct(
        public RouteEntry $route,
        array $rawParameters = [],
        public array $middleware = [],
    ) {
        $this->parameters = new RouteParameters($rawParameters);
    }

    /**
     * @return string|array{0: string, 1: string}|Closure
     */
    public function handler(): string|array|Closure
    {
        return $this->route->handler;
    }

    public function routeName(): ?string
    {
        return $this->route->name;
    }
}
