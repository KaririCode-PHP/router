<?php

declare(strict_types=1);

namespace KaririCode\Router\Matcher;

use KaririCode\Router\Contract\RouteMatcher;
use KaririCode\Router\Core\MatchResult;
use KaririCode\Router\Core\RouteEntry;

/**
 * Two-tier composite matcher: static O(1) first, then compiled regex.
 *
 * ARFA 1.3 P2 (Reactive Flow Composition): static → regex pipeline.
 *
 * Spec V4.0: final class.
 *
 * Production workloads where 60–80% of traffic hits literal paths
 * (e.g. /health, /api/v1/status) benefit from the fast-path hash
 * lookup before falling through to regex alternation.
 *
 * ADR-001: Composite over single-strategy for measurable throughput gain.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc3986#section-3.3 URI path matching
 */
final class CompositeRouteMatcher implements RouteMatcher
{
    private readonly StaticMatcher $staticMatcher;
    private readonly RegexMatcher $regexMatcher;

    public function __construct(
        ?StaticMatcher $staticMatcher = null,
        ?RegexMatcher $regexMatcher = null,
    ) {
        $this->staticMatcher = $staticMatcher ?? new StaticMatcher();
        $this->regexMatcher = $regexMatcher ?? new RegexMatcher();
    }

    public function match(string $method, string $uri): ?MatchResult
    {
        // Fast path: O(1) static lookup
        return $this->staticMatcher->match($method, $uri)
            ?? $this->regexMatcher->match($method, $uri);
    }

    public function add(RouteEntry $route): void
    {
        // Route goes to one matcher based on whether it has parameters
        $this->staticMatcher->add($route);
        $this->regexMatcher->add($route);
    }

    public function allowedMethods(string $uri): array
    {
        $static = $this->staticMatcher->allowedMethods($uri);
        if ($static !== []) {
            return $static;
        }

        return $this->regexMatcher->allowedMethods($uri);
    }

    /**
     * Access the inner StaticMatcher (used by CachedMatcher for export).
     */
    public function getStaticMatcher(): StaticMatcher
    {
        return $this->staticMatcher;
    }

    /**
     * Access the inner RegexMatcher (used by CachedMatcher for export).
     */
    public function getRegexMatcher(): RegexMatcher
    {
        return $this->regexMatcher;
    }
}
