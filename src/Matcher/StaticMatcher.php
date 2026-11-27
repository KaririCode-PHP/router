<?php

declare(strict_types=1);

namespace KaririCode\Router\Matcher;

use KaririCode\Router\Contract\RouteMatcher;
use KaririCode\Router\Core\MatchResult;
use KaririCode\Router\Core\RouteEntry;

/**
 * The first tier of CompositeRouteMatcher: routes without placeholders, in
 * a hash map from path to method to route, so a literal path such as
 * /health resolves with one array lookup however many routes exist.
 *
 * Preconditions: add() ignores routes whose pattern has placeholders;
 * RegexMatcher serves those. When two routes claim the same method and
 * path, the first one registered keeps it, as in RegexMatcher; the
 * registry rejects such a second route before it gets here.
 *
 * Thread-safety: written during boot, read afterwards.
 *
 * Side effects: add() changes the map.
 *
 * Example:
 *
 *     $matcher = new StaticMatcher();
 *     $matcher->add(new RouteEntry(['GET', 'HEAD'], '/health', 'HealthCheck'));
 *     $matcher->match('HEAD', '/health')?->handler(); // 'HealthCheck'
 *
 * References: ADR-001 (composite matching); SPEC-001 §5.1.
 *
 * @see https://www.php.net/manual/en/language.types.array.php
 */
final class StaticMatcher implements RouteMatcher
{
    /** @var array<string, array<string, RouteEntry>> path to uppercase method to route */
    private array $routes = [];

    #[\Override]
    public function match(string $method, string $uri): ?MatchResult
    {
        $route = $this->routes[$uri][strtoupper($method)] ?? null;

        return $route === null ? null : new MatchResult($route, $route->defaults, $route->middleware);
    }

    #[\Override]
    public function add(RouteEntry $route): void
    {
        if (!$route->compiledPattern->isStatic) {
            return;
        }

        foreach ($route->methods as $method) {
            $this->routes[$route->pattern][$method] ??= $route;
        }
    }

    #[\Override]
    public function allowedMethods(string $uri): array
    {
        return array_keys($this->routes[$uri] ?? []);
    }

    /**
     * The map, for CachedMatcher to write out.
     *
     * @return array<string, array<string, RouteEntry>>
     */
    public function exportMap(): array
    {
        return $this->routes;
    }
}
