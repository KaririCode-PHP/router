<?php

declare(strict_types=1);

namespace KaririCode\Router\Contract;

use KaririCode\Router\Core\MatchResult;
use KaririCode\Router\Core\RouteEntry;

/**
 * Primary routing contract.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): the contract accepts raw
 * strings so any protocol adapter can invoke it without PSR-7
 * coupling.
 *
 * Spec V4.0 §1.3: interface without "Interface" suffix.
 *
 * Implementations resolve a (method, uri) pair to a {@see MatchResult}
 * containing the matched route, extracted parameters, and middleware stack.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc3986#section-3.3 URI path component
 */
interface Router
{
    /**
     * @throws \KaririCode\Router\Exception\RouteNotFoundException
     * @throws \KaririCode\Router\Exception\MethodNotAllowedException
      *
      * @see https://datatracker.ietf.org/doc/html/rfc3986#section-3.3 URI path component
     */
    public function match(string $method, string $uri): MatchResult;

    public function addRoute(RouteEntry $route): void;

    /**
     * @return list<RouteEntry>
      *
      * @see https://datatracker.ietf.org/doc/html/rfc3986#section-3.3 URI path component
     */
    public function routes(): array;
}

