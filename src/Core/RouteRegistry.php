<?php

declare(strict_types=1);

namespace KaririCode\Router\Core;

use Closure;
use KaririCode\Router\Contract\RouteMatcher;
use KaririCode\Router\Contract\Router;
use KaririCode\Router\Exception\MethodNotAllowedException;
use KaririCode\Router\Exception\RouteCompilationException;
use KaririCode\Router\Exception\RouteNotFoundException;
use KaririCode\Router\Matcher\CompositeRouteMatcher;

/**
 * The application's routing table: routes are registered here during boot,
 * through the fluent methods, groups, loaders or addRoute(), and matched
 * here on every request. Matching is delegated to a RouteMatcher
 * (CompositeRouteMatcher unless another is given, CachedMatcher for a
 * cached table); the registry keeps the list, the names and the rules a
 * route must satisfy to enter it.
 *
 * Preconditions: addRoute() rejects, with RouteCompilationException, a
 * pattern that does not start with "/", a second route for a method and
 * pattern already registered, and a second route with a name already
 * taken: each would make one route unreachable or one name ambiguous. The
 * fluent get() registers HEAD with GET (RFC 9110 §9.3.2). match() adds a
 * missing leading "/" and matches the path as given, without decoding.
 *
 * Thread-safety: register during boot, then only match; the registry is
 * not synchronised.
 *
 * Side effects: registration changes the registry and its matcher; match()
 * changes nothing.
 *
 * Example:
 *
 *     $router = new RouteRegistry();
 *     $router->get('/users/{id:int}', [UserController::class, 'show'], 'users.show')
 *         ->group('/admin', static function (RouteGroup $admin): void {
 *             $admin->get('/stats', [AdminController::class, 'stats'], 'stats');
 *         }, middleware: ['auth.admin'], namePrefix: 'admin.');
 *
 *     $router->match('GET', '/users/42')->parameters->get('id'); // '42'
 *     $router->namedRoute('admin.stats')?->pattern;                // '/admin/stats'
 *
 * References: ADR-001, ADR-002; SPEC-001 §2; RFC 9110 §9.3.2 (HEAD).
 *
 * @see https://www.rfc-editor.org/rfc/rfc9110#section-9.3.2
 */
final class RouteRegistry implements Router
{
    /** @var list<RouteEntry> */
    private array $routes = [];

    /** @var array<string, RouteEntry> */
    private array $named = [];

    /** @var array<string, true> "METHOD pattern" of every registered route */
    private array $claimed = [];

    public function __construct(
        private readonly RouteMatcher $matcher = new CompositeRouteMatcher(),
    ) {}

    /**
     * Registers a route for method strings of any protocol.
     *
     * @param list<string>                               $methods
     * @param string|array{0: string, 1: string}|Closure $handler
     * @param list<string>                               $middleware
     * @param array<string, string>                      $defaults
     * @param array<string, mixed>                       $metadata
     */
    public function route(
        array $methods,
        string $pattern,
        string|array|Closure $handler,
        ?string $name = null,
        array $middleware = [],
        array $defaults = [],
        array $metadata = [],
    ): self {
        $this->addRoute(new RouteEntry($methods, $pattern, $handler, $name, $middleware, $defaults, $metadata));

        return $this;
    }

    /**
     * Registers GET and HEAD.
     *
     * @param string|array{0: string, 1: string}|Closure $handler
     * @param list<string>                               $middleware
     */
    public function get(string $pattern, string|array|Closure $handler, ?string $name = null, array $middleware = []): self
    {
        return $this->route(['GET', 'HEAD'], $pattern, $handler, $name, $middleware);
    }

    /**
     * @param string|array{0: string, 1: string}|Closure $handler
     * @param list<string>                               $middleware
     */
    public function post(string $pattern, string|array|Closure $handler, ?string $name = null, array $middleware = []): self
    {
        return $this->route(['POST'], $pattern, $handler, $name, $middleware);
    }

    /**
     * @param string|array{0: string, 1: string}|Closure $handler
     * @param list<string>                               $middleware
     */
    public function put(string $pattern, string|array|Closure $handler, ?string $name = null, array $middleware = []): self
    {
        return $this->route(['PUT'], $pattern, $handler, $name, $middleware);
    }

    /**
     * @param string|array{0: string, 1: string}|Closure $handler
     * @param list<string>                               $middleware
     */
    public function patch(string $pattern, string|array|Closure $handler, ?string $name = null, array $middleware = []): self
    {
        return $this->route(['PATCH'], $pattern, $handler, $name, $middleware);
    }

    /**
     * @param string|array{0: string, 1: string}|Closure $handler
     * @param list<string>                               $middleware
     */
    public function delete(string $pattern, string|array|Closure $handler, ?string $name = null, array $middleware = []): self
    {
        return $this->route(['DELETE'], $pattern, $handler, $name, $middleware);
    }

    /**
     * Registers the routes $define adds to a group that prefixes their
     * patterns, prepends middleware and prefixes their names.
     *
     * @param callable(RouteGroup): void $define
     * @param list<string>               $middleware
     */
    public function group(string $prefix, callable $define, array $middleware = [], ?string $namePrefix = null): self
    {
        $group = new RouteGroup($prefix, $middleware, $namePrefix);
        $define($group);

        foreach ($group->resolve() as $route) {
            $this->addRoute($route);
        }

        return $this;
    }

    /**
     * @throws RouteCompilationException when the pattern does not start with "/", or the route or its name is taken
     */
    #[\Override]
    public function addRoute(RouteEntry $route): void
    {
        if (!str_starts_with($route->pattern, '/')) {
            throw RouteCompilationException::invalidPattern($route->pattern, 'a registered pattern starts with "/"');
        }

        foreach ($route->methods as $method) {
            if (isset($this->claimed[$method . ' ' . $route->pattern])) {
                throw RouteCompilationException::duplicateRoute($method, $route->pattern);
            }
        }

        if ($route->name !== null && isset($this->named[$route->name])) {
            throw RouteCompilationException::duplicateRouteName($route->name);
        }

        $this->matcher->add($route);
        $this->routes[] = $route;

        foreach ($route->methods as $method) {
            $this->claimed[$method . ' ' . $route->pattern] = true;
        }

        if ($route->name !== null) {
            $this->named[$route->name] = $route;
        }
    }

    #[\Override]
    public function match(string $method, string $uri): MatchResult
    {
        $path = '/' . ltrim($uri, '/');
        $result = $this->matcher->match($method, $path);

        if ($result !== null) {
            return $result;
        }

        $allowed = $this->matcher->allowedMethods($path);

        throw $allowed === [] ? RouteNotFoundException::forRequest() : MethodNotAllowedException::allowing($allowed);
    }

    #[\Override]
    public function routes(): array
    {
        return $this->routes;
    }

    /** The route registered under $name, or null: the find() form for URL generation. */
    public function namedRoute(string $name): ?RouteEntry
    {
        return $this->named[$name] ?? null;
    }
}
