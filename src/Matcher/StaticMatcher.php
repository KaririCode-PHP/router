<?php

declare(strict_types=1);

namespace KaririCode\Router\Matcher;

use KaririCode\Router\Contract\RouteMatcher;
use KaririCode\Router\Core\MatchResult;
use KaririCode\Router\Core\RouteEntry;

/**
 * Hash-map matcher for static (parameterless) routes.
 *
 * ARFA 1.3 P2 (Reactive Flow Composition): first stage of the
 * composite matcher pipeline — O(1) hash lookup before regex fallback.
 * Spec V4.0: final class.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc3986#section-3.3 URI path matching
 *
 * Achieves O(1) lookup for literal paths, which typically account
 * for 60–80% of routes in real-world applications. The Composite
 * matcher delegates here first before falling back to the Radix Tree.
 *
 * Storage layout:  uri → method → RouteEntry
 */
final class StaticMatcher implements RouteMatcher
{
    /** @var array<string, array<string, RouteEntry>> */
    private array $map = [];

    public function match(string $method, string $uri): ?MatchResult
    {
        $entry = $this->map[$uri][strtoupper($method)] ?? null;

        if ($entry === null) {
            return null;
        }

        return new MatchResult(
            route: $entry,
            rawParameters: [],
            middleware: $entry->middleware,
        );
    }

    public function add(RouteEntry $route): void
    {
        if (!$route->compiledPattern->isStatic) {
            return;
        }

        foreach ($route->methods as $method) {
            $this->map[$route->pattern][$method] = $route;
        }
    }

    public function allowedMethods(string $uri): array
    {
        if (!isset($this->map[$uri])) {
            return [];
        }

        return array_keys($this->map[$uri]);
    }

    /**
     * Export the static route map for cache serialization.
     *
     * Format: [uri => [method => RouteEntry]].
     * Used by {@see \KaririCode\Router\Matcher\CachedMatcher::warm()}.
     *
     * @return array<string, array<string, RouteEntry>>
     */
    public function exportMap(): array
    {
        return $this->map;
    }
}
