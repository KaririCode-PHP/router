<?php

declare(strict_types=1);

namespace KaririCode\Router\Core;

use KaririCode\Router\Contract\Router;
use KaririCode\Router\Contract\RouteMatcher;
use KaririCode\Router\Exception\MethodNotAllowedException;
use KaririCode\Router\Exception\RouteNotFoundException;
use KaririCode\Router\Matcher\CompositeRouteMatcher;

/**
 * Central route registry and dispatcher.
 *
 * ARFA 1.3 principles applied:
 *  P1 — MatchResult is immutable
 *  P4 — Accepts raw strings, no PSR-7 coupling; any protocol can register routes
 *  P5 — Emits match events when EventDispatcherBridge is wired
 *
 * Spec V4.0: final class. Implements the {@see Router} contract.
 *
 * Delegates matching to a pluggable {@see RouteMatcher} strategy
 * (defaults to {@see CompositeRouteMatcher} which combines a static
 * map for literal routes and a Radix-Tree for parameterized routes).
 *
 * @see https://datatracker.ietf.org/doc/html/rfc3986#section-3.3 URI path component
 */
final class RouteRegistry implements Router
{
    /** @var array<string, RouteEntry> name → entry index */
    private array $namedRoutes = [];

    /** @var list<RouteEntry> */
    private array $allRoutes = [];

    public function __construct(
        private readonly RouteMatcher $matcher = new CompositeRouteMatcher(),
    ) {
    }

    // ── Protocol-Agnostic Registration (ARFA 1.3 P4) ─────────────────────

    /**
     * Registers a route for arbitrary method strings (any protocol).
     *
     * @param list<string>                                    $methods    Uppercase method strings
     * @param string|callable|array{0:class-string,1:string}  $handler
     * @param list<string|class-string>                        $middleware
     * @param array<string, string>                            $defaults
     * @param array<string, mixed>                             $metadata
      *
      * @see https://datatracker.ietf.org/doc/html/rfc3986#section-3.3 URI path component
     */
    public function route(
        array $methods,
        string $pattern,
        string|callable|array $handler,
        ?string $name = null,
        array $middleware = [],
        array $defaults = [],
        array $metadata = [],
    ): self {
        $this->addRoute(new RouteEntry(
            methods: $methods,
            pattern: $pattern,
            handler: $handler,
            name: $name,
            middleware: $middleware,
            defaults: $defaults,
            metadata: $metadata,
        ));

        return $this;
    }

    // ── HTTP Fluent Shortcuts ────────────────────────────────────────────

    /**
     * @param string|callable|array{0:class-string,1:string} $handler
     * @param list<string|class-string>                       $middleware
      *
      * @see https://datatracker.ietf.org/doc/html/rfc3986#section-3.3 URI path component
     */
    public function get(string $pattern, string|callable|array $handler, ?string $name = null, array $middleware = []): self
    {
        return $this->register(['GET', 'HEAD'], $pattern, $handler, $name, $middleware);
    }

    public function post(string $pattern, string|callable|array $handler, ?string $name = null, array $middleware = []): self
    {
        return $this->register(['POST'], $pattern, $handler, $name, $middleware);
    }

    public function put(string $pattern, string|callable|array $handler, ?string $name = null, array $middleware = []): self
    {
        return $this->register(['PUT'], $pattern, $handler, $name, $middleware);
    }

    public function patch(string $pattern, string|callable|array $handler, ?string $name = null, array $middleware = []): self
    {
        return $this->register(['PATCH'], $pattern, $handler, $name, $middleware);
    }

    public function delete(string $pattern, string|callable|array $handler, ?string $name = null, array $middleware = []): self
    {
        return $this->register(['DELETE'], $pattern, $handler, $name, $middleware);
    }

    /**
     * @param list<string|class-string> $middleware
      *
      * @see https://datatracker.ietf.org/doc/html/rfc3986#section-3.3 URI path component
     */
    public function group(string $prefix, callable $callback, array $middleware = [], ?string $namePrefix = null): self
    {
        $group = new RouteGroup(
            prefix: $prefix,
            middleware: $middleware,
            namePrefix: $namePrefix,
        );

        $callback($group);

        foreach ($group->resolve() as $route) {
            $this->addRoute($route);
        }

        return $this;
    }

    /**
     * @param list<string>              $methods
     * @param list<string|class-string> $middleware
      *
      * @see https://datatracker.ietf.org/doc/html/rfc3986#section-3.3 URI path component
     */
    private function register(array $methods, string $pattern, string|callable|array $handler, ?string $name, array $middleware): self
    {
        $this->addRoute(new RouteEntry(
            methods: $methods,
            pattern: $pattern,
            handler: $handler,
            name: $name,
            middleware: $middleware,
        ));

        return $this;
    }

    // ── Router Contract ──────────────────────────────────────────────────

    public function addRoute(RouteEntry $route): void
    {
        $this->matcher->add($route);
        $this->allRoutes[] = $route;

        if ($route->name !== null) {
            $this->namedRoutes[$route->name] = $route;
        }
    }

    public function match(string $method, string $uri): MatchResult
    {
        $uri = '/' . ltrim($uri, '/');
        $result = $this->matcher->match($method, $uri);

        if ($result !== null) {
            return $result;
        }

        $allowed = $this->matcher->allowedMethods($uri);
        if ($allowed !== []) {
            throw new MethodNotAllowedException($method, $uri, $allowed);
        }

        throw new RouteNotFoundException($method, $uri);
    }

    /** @return list<RouteEntry> */
    public function routes(): array
    {
        return $this->allRoutes;
    }

    public function namedRoute(string $name): ?RouteEntry
    {
        return $this->namedRoutes[$name] ?? null;
    }
}

