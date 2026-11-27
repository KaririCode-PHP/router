<?php

declare(strict_types=1);

namespace KaririCode\Router\Core;

/**
 * Fluent API for defining route groups with shared attributes.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): methods are raw uppercase
 * strings — HTTP shortcuts
 * (get/post/put/patch/delete/options/any) are convenience wrappers
 * around map(), which accepts any protocol's method strings.
 *
 * Spec V4.0: final class. Not readonly because it accumulates
 * entries during the registration phase (builder pattern).
 *
 * Groups can nest: inner groups inherit and prepend the outer
 * group's prefix, middleware, name prefix, host, and scheme
 * constraints.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc3986#section-3 URI component grouping
 */
final class RouteGroup
{
    /** @var list<RouteEntry|self> */
    private array $entries = [];

    /**
     * @param list<string|class-string> $middleware
     */
    public function __construct(
        private readonly string $prefix = '',
        private readonly array $middleware = [],
        private readonly ?string $namePrefix = null,
        private readonly ?string $host = null,
        private readonly ?string $scheme = null,
    ) {
    }

    // ── HTTP Verb Shortcuts ──────────────────────────────────────────────

    /**
     * @param string|callable|array{0:class-string,1:string} $handler
     * @param list<string|class-string>                       $middleware
     */
    public function get(string $pattern, string|callable|array $handler, ?string $name = null, array $middleware = []): self
    {
        return $this->map(['GET', 'HEAD'], $pattern, $handler, $name, $middleware);
    }

    public function post(string $pattern, string|callable|array $handler, ?string $name = null, array $middleware = []): self
    {
        return $this->map(['POST'], $pattern, $handler, $name, $middleware);
    }

    public function put(string $pattern, string|callable|array $handler, ?string $name = null, array $middleware = []): self
    {
        return $this->map(['PUT'], $pattern, $handler, $name, $middleware);
    }

    public function patch(string $pattern, string|callable|array $handler, ?string $name = null, array $middleware = []): self
    {
        return $this->map(['PATCH'], $pattern, $handler, $name, $middleware);
    }

    public function delete(string $pattern, string|callable|array $handler, ?string $name = null, array $middleware = []): self
    {
        return $this->map(['DELETE'], $pattern, $handler, $name, $middleware);
    }

    public function options(string $pattern, string|callable|array $handler, ?string $name = null, array $middleware = []): self
    {
        return $this->map(['OPTIONS'], $pattern, $handler, $name, $middleware);
    }

    /**
     * Register a route for all standard HTTP methods.
     */
    public function any(string $pattern, string|callable|array $handler, ?string $name = null, array $middleware = []): self
    {
        return $this->map(
            ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
            $pattern,
            $handler,
            $name,
            $middleware,
        );
    }

    /**
     * @param list<string>              $methods    Uppercase method strings (any protocol)
     * @param list<string|class-string> $middleware
     */
    public function map(array $methods, string $pattern, string|callable|array $handler, ?string $name = null, array $middleware = []): self
    {
        $this->entries[] = new RouteEntry(
            methods: $methods,
            pattern: $pattern,
            handler: $handler,
            name: $name,
            middleware: $middleware,
        );

        return $this;
    }

    // ── Nesting ──────────────────────────────────────────────────────────

    /**
     * @param list<string|class-string> $middleware
     */
    public function group(string $prefix, callable $callback, array $middleware = [], ?string $namePrefix = null): self
    {
        $child = new self(
            prefix: $prefix,
            middleware: $middleware,
            namePrefix: $namePrefix,
        );

        $callback($child);
        $this->entries[] = $child;

        return $this;
    }

    // ── Resolution ───────────────────────────────────────────────────────

    /**
     * Flatten this group (and nested groups) into concrete RouteEntry
     * instances with fully resolved prefixes, middleware, and names.
     *
     * @return list<RouteEntry>
     */
    public function resolve(): array
    {
        $routes = [];

        foreach ($this->entries as $entry) {
            if ($entry instanceof self) {
                foreach ($entry->resolve() as $nested) {
                    $routes[] = $this->applyGroupAttributes($nested);
                }
            } else {
                $routes[] = $this->applyGroupAttributes($entry);
            }
        }

        return $routes;
    }

    private function applyGroupAttributes(RouteEntry $route): RouteEntry
    {
        $name = $route->name;
        if ($name !== null && $this->namePrefix !== null) {
            $name = $this->namePrefix . $name;
        }

        return new RouteEntry(
            methods: $route->methods,
            pattern: rtrim($this->prefix, '/') . $route->pattern,
            handler: $route->handler,
            name: $name,
            middleware: [...$this->middleware, ...$route->middleware],
            defaults: $route->defaults,
            metadata: $route->metadata,
            host: $route->host ?? $this->host,
            scheme: $route->scheme ?? $this->scheme,
        );
    }
}
