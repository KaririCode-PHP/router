<?php

declare(strict_types=1);

namespace KaririCode\Router\Core;

use Closure;

/**
 * Collects routes that share a path prefix, middleware and a name prefix,
 * during RouteRegistry::group(). Groups nest: an inner group's routes get
 * the inner attributes first, then the outer ones, so a route named
 * 'stats' in group 'admin.' inside group 'api.' is 'api.admin.stats'.
 *
 * Preconditions: patterns inside a group are relative to its prefix and
 * usually start with "/"; an empty pattern stands for the prefix itself. A
 * trailing "/" of the prefix is dropped when joining.
 *
 * Thread-safety: a builder, used once during boot.
 *
 * Side effects: the fluent methods add entries to the group.
 *
 * Example:
 *
 *     $router->group('/api/v1', static function (RouteGroup $api): void {
 *         $api->get('/users', 'users.index', 'users.index')
 *             ->group('/admin', static function (RouteGroup $admin): void {
 *                 $admin->get('/stats', 'admin.stats', 'stats');
 *             }, middleware: ['auth.admin'], namePrefix: 'admin.');
 *     }, middleware: ['auth.api'], namePrefix: 'api.');
 *     // GET /api/v1/admin/stats, named api.admin.stats, middleware auth.api then auth.admin
 *
 * References: SPEC-001 §2.3 (groups); code review BUG-004, which turned
 * out to be a test that named its route 'admin.stats' inside 'admin.'.
 *
 * @see https://www.rfc-editor.org/rfc/rfc3986#section-3.3
 */
final class RouteGroup
{
    /** @var list<RouteEntry|self> */
    private array $entries = [];

    /**
     * @param list<string> $middleware
     */
    public function __construct(
        private readonly string $prefix = '',
        private readonly array $middleware = [],
        private readonly ?string $namePrefix = null,
    ) {}

    /**
     * Adds GET and HEAD.
     *
     * @param string|array{0: string, 1: string}|Closure $handler
     * @param list<string>                               $middleware
     */
    public function get(string $pattern, string|array|Closure $handler, ?string $name = null, array $middleware = []): self
    {
        return $this->map(['GET', 'HEAD'], $pattern, $handler, $name, $middleware);
    }

    /**
     * @param string|array{0: string, 1: string}|Closure $handler
     * @param list<string>                               $middleware
     */
    public function post(string $pattern, string|array|Closure $handler, ?string $name = null, array $middleware = []): self
    {
        return $this->map(['POST'], $pattern, $handler, $name, $middleware);
    }

    /**
     * @param string|array{0: string, 1: string}|Closure $handler
     * @param list<string>                               $middleware
     */
    public function put(string $pattern, string|array|Closure $handler, ?string $name = null, array $middleware = []): self
    {
        return $this->map(['PUT'], $pattern, $handler, $name, $middleware);
    }

    /**
     * @param string|array{0: string, 1: string}|Closure $handler
     * @param list<string>                               $middleware
     */
    public function patch(string $pattern, string|array|Closure $handler, ?string $name = null, array $middleware = []): self
    {
        return $this->map(['PATCH'], $pattern, $handler, $name, $middleware);
    }

    /**
     * @param string|array{0: string, 1: string}|Closure $handler
     * @param list<string>                               $middleware
     */
    public function delete(string $pattern, string|array|Closure $handler, ?string $name = null, array $middleware = []): self
    {
        return $this->map(['DELETE'], $pattern, $handler, $name, $middleware);
    }

    /**
     * @param string|array{0: string, 1: string}|Closure $handler
     * @param list<string>                               $middleware
     */
    public function options(string $pattern, string|array|Closure $handler, ?string $name = null, array $middleware = []): self
    {
        return $this->map(['OPTIONS'], $pattern, $handler, $name, $middleware);
    }

    /**
     * Adds the seven methods of HttpMethod.
     *
     * @param string|array{0: string, 1: string}|Closure $handler
     * @param list<string>                               $middleware
     */
    public function any(string $pattern, string|array|Closure $handler, ?string $name = null, array $middleware = []): self
    {
        return $this->map(['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], $pattern, $handler, $name, $middleware);
    }

    /**
     * Adds a route for method strings of any protocol; the same fields as
     * RouteRegistry::route().
     *
     * @param list<string>                               $methods
     * @param string|array{0: string, 1: string}|Closure $handler
     * @param list<string>                               $middleware
     * @param array<string, string>                      $defaults
     * @param array<string, mixed>                       $metadata
     */
    public function map(
        array $methods,
        string $pattern,
        string|array|Closure $handler,
        ?string $name = null,
        array $middleware = [],
        array $defaults = [],
        array $metadata = [],
    ): self {
        $this->entries[] = new RouteEntry($methods, $pattern, $handler, $name, $middleware, $defaults, $metadata);

        return $this;
    }

    /**
     * Adds a nested group.
     *
     * @param callable(self): void $define
     * @param list<string>         $middleware
     */
    public function group(string $prefix, callable $define, array $middleware = [], ?string $namePrefix = null): self
    {
        $child = new self($prefix, $middleware, $namePrefix);
        $define($child);
        $this->entries[] = $child;

        return $this;
    }

    /**
     * The group's routes with every prefix and middleware applied, nested
     * groups included, in the order they were added.
     *
     * @return list<RouteEntry>
     */
    public function resolve(): array
    {
        $routes = [];

        foreach ($this->entries as $entry) {
            foreach ($entry instanceof self ? $entry->resolve() : [$entry] as $route) {
                $routes[] = $this->apply($route);
            }
        }

        return $routes;
    }

    private function apply(RouteEntry $route): RouteEntry
    {
        $route = $route->withPrefix($this->prefix)->withPrependedMiddleware($this->middleware);

        return $this->namePrefix === null ? $route : $route->withNamePrefix($this->namePrefix);
    }
}
