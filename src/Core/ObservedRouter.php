<?php

declare(strict_types=1);

namespace KaririCode\Router\Core;

use KaririCode\Router\Contract\MatchObserver;
use KaririCode\Router\Contract\Router;
use KaririCode\Router\Exception\MethodNotAllowedException;
use KaririCode\Router\Exception\RouteNotFoundException;

/**
 * A Router that times every match on the monotonic clock and reports the
 * outcome to a MatchObserver, then returns the result or rethrows the miss
 * unchanged. Observability is opt-in: a plain RouteRegistry pays nothing
 * for it.
 *
 * Preconditions: wrap the router after its routes are registered, or
 * register through either object: addRoute() is forwarded.
 *
 * Thread-safety: keeps no state of its own.
 *
 * Side effects: one observer notification per match.
 *
 * Example:
 *
 *     $statistics = new MatchStatistics();
 *     $router = new ObservedRouter($registry, $statistics);
 *     $router->match('GET', '/health');
 *     $statistics->averageDurationNs();
 *
 * References: ARFA 1.3 P5 (continuous observability); ADR-009; GoF
 * Decorator.
 *
 * @see https://www.php.net/manual/en/function.hrtime.php
 */
final readonly class ObservedRouter implements Router
{
    public function __construct(
        private Router $router,
        private MatchObserver $observer,
    ) {}

    #[\Override]
    public function match(string $method, string $uri): MatchResult
    {
        $started = self::now();

        try {
            $result = $this->router->match($method, $uri);
        } catch (RouteNotFoundException|MethodNotAllowedException $failure) {
            $this->observer->routeMissed($method, $failure, self::since($started));

            throw $failure;
        }

        $this->observer->routeMatched($result, self::since($started));

        return $result;
    }

    #[\Override]
    public function addRoute(RouteEntry $route): void
    {
        $this->router->addRoute($route);
    }

    #[\Override]
    public function routes(): array
    {
        return $this->router->routes();
    }

    /** Monotonic nanoseconds; hrtime() is a float only where an int would overflow. */
    private static function now(): int
    {
        return (int) hrtime(true);
    }

    private static function since(int $started): int
    {
        return self::now() - $started;
    }
}
