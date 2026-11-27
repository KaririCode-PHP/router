<?php

declare(strict_types=1);

namespace KaririCode\Router\Contract;

use KaririCode\Router\Core\MatchResult;
use KaririCode\Router\Core\RouteEntry;

/**
 * Strategy interface for route matching algorithms.
 *
 * ARFA 1.3 P2 (Reactive Flow Composition): matchers are composable
 * pipeline stages — StaticMatcher → RegexMatcher via CompositeRouteMatcher.
 * Spec V4.0 §1.3: interface without "Interface" suffix.
 *
 * Enables swapping between static map, compiled regex, cached, or
 * composite matchers without modifying the Router core.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc3986#section-3.3 URI path matching
 */
interface RouteMatcher
{
    public function match(string $method, string $uri): ?MatchResult;

    public function add(RouteEntry $route): void;

    /**
     * @return list<string> HTTP methods registered for the given URI.
     */
    public function allowedMethods(string $uri): array;
}
