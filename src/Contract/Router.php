<?php

declare(strict_types=1);

namespace KaririCode\Router\Contract;

use KaririCode\Router\Core\MatchResult;
use KaririCode\Router\Core\RouteEntry;
use KaririCode\Router\Exception\MethodNotAllowedException;
use KaririCode\Router\Exception\RouteNotFoundException;

/**
 * Resolves a (method, path) pair to the route that serves it. Both are
 * plain strings, so an adapter for any protocol can call it without the
 * router knowing the protocol's request type (ARFA 1.3 P4).
 *
 * Preconditions: match() expects the path only, without query string;
 * implementations add a missing leading "/". A miss throws, because the
 * caller asked for a route it needs (get semantics); RouteMatcher::match()
 * is the nullable form.
 *
 * Thread-safety: an implementation is built during boot and then only
 * read; registering routes while requests are matched is not supported.
 *
 * Side effects: addRoute() changes the router's state; match() and
 * routes() do not.
 *
 * Example:
 *
 *     $router->addRoute(new RouteEntry(['GET'], '/health', 'HealthCheck'));
 *     $router->match('GET', '/health')->handler(); // 'HealthCheck'
 *
 * References: ADR-002; KaririCode Spec V4.3.3 §8.1 and errata E-036 (get
 * throws, find returns null); RFC 3986 §3.3.
 *
 * @see https://www.rfc-editor.org/rfc/rfc3986#section-3.3
 */
interface Router
{
    /**
     * @throws RouteNotFoundException    when no route has the path
     * @throws MethodNotAllowedException when the path exists for other methods only
     */
    public function match(string $method, string $uri): MatchResult;

    public function addRoute(RouteEntry $route): void;

    /**
     * @return list<RouteEntry> in registration order
     */
    public function routes(): array;
}
