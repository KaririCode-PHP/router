<?php

declare(strict_types=1);

namespace KaririCode\Router\Matcher;

use KaririCode\Router\Contract\RouteMatcher;
use KaririCode\Router\Core\MatchResult;
use KaririCode\Router\Core\RouteEntry;

/**
 * The second tier of CompositeRouteMatcher: routes with placeholders,
 * joined per method into a few combined expressions, so a request costs one
 * preg_match per chunk of routes instead of one per route.
 *
 * Each chunk is a branch-reset group, `#\A(?|/users/([0-9]+)(*MARK:0)|
 * /posts/([^/]+)/([^/]+)(*MARK:1))\z#u`. Branch reset numbers the groups of
 * every alternative from 1, and the MARK verb names the alternative that
 * matched, so captures map to parameters by position and two routes may
 * share a parameter name. This is the mark-based technique of
 * nikic/FastRoute; it replaces the backward walk over empty groups of 4.0,
 * which never found a route (code review BUG-003), and named groups, which
 * PCRE rejects when two routes in one chunk use the same name.
 *
 * Preconditions: add() ignores routes without placeholders. Within a
 * method, the first route registered wins when several patterns match one
 * path. Compilation is lazy and repeats after every add().
 *
 * Thread-safety: written during boot, read afterwards.
 *
 * Side effects: add() changes the route list and drops the compiled chunks.
 *
 * Example:
 *
 *     $matcher = new RegexMatcher();
 *     $matcher->add(new RouteEntry(['GET'], '/users/{id:int}', 'users.show'));
 *     $matcher->add(new RouteEntry(['GET'], '/posts/{id}', 'posts.show'));
 *     $matcher->match('GET', '/posts/intro')?->parameters->get('id'); // 'intro'
 *
 * References: ADR-001; SPEC-001 §5.2; PCRE2 branch reset and backtracking
 * control verbs; N. Popov, "Fast request routing using regular expressions".
 *
 * @see https://www.pcre.org/current/doc/html/pcre2pattern.html#SEC15
 */
final class RegexMatcher implements RouteMatcher
{
    /** Routes per combined expression, FastRoute's size for mark-based chunks. */
    private const int CHUNK_SIZE = 30;

    /** @var array<string, list<RouteEntry>> uppercase method to routes, in registration order */
    private array $routesByMethod = [];

    /** @var array<string, list<array{regex: non-empty-string, routes: list<RouteEntry>}>>|null */
    private ?array $compiled = null;

    /**
     * Runs one combined expression against a path: the index of the
     * alternative that matched and its captures, or null on a miss.
     * CachedMatcher runs the same expressions from its stored table.
     *
     * @param non-empty-string $regex
     *
     * @return array{int, list<string>}|null
     */
    public static function locate(string $regex, string $uri): ?array
    {
        if (preg_match($regex, $uri, $matches) !== 1 || !isset($matches['MARK'])) {
            return null;
        }

        $captures = [];

        for ($group = 1; isset($matches[$group]); ++$group) {
            $captures[] = $matches[$group];
        }

        return [(int) $matches['MARK'], $captures];
    }

    #[\Override]
    public function match(string $method, string $uri): ?MatchResult
    {
        foreach ($this->compiled()[strtoupper($method)] ?? [] as $chunk) {
            $hit = self::locate($chunk['regex'], $uri);
            $route = $hit === null ? null : $chunk['routes'][$hit[0]] ?? null;

            if ($hit !== null && $route !== null) {
                return new MatchResult($route, $route->parametersFrom($hit[1]), $route->middleware);
            }
        }

        return null;
    }

    #[\Override]
    public function add(RouteEntry $route): void
    {
        if ($route->compiledPattern->isStatic) {
            return;
        }

        foreach ($route->methods as $method) {
            $this->routesByMethod[$method][] = $route;
        }

        $this->compiled = null;
    }

    #[\Override]
    public function allowedMethods(string $uri): array
    {
        $allowed = [];

        foreach ($this->compiled() as $method => $chunks) {
            if (array_any($chunks, static fn(array $chunk): bool => self::locate($chunk['regex'], $uri) !== null)) {
                $allowed[] = $method;
            }
        }

        return $allowed;
    }

    /**
     * The routes per method, in registration order, without compiling.
     *
     * @return array<string, list<RouteEntry>>
     */
    public function exportRoutes(): array
    {
        return $this->routesByMethod;
    }

    /**
     * The compiled chunks per method, for CachedMatcher to write out.
     *
     * @return array<string, list<array{regex: non-empty-string, routes: list<RouteEntry>}>>
     */
    public function exportCompiled(): array
    {
        return $this->compiled();
    }

    /**
     * @return array<string, list<array{regex: non-empty-string, routes: list<RouteEntry>}>>
     */
    private function compiled(): array
    {
        if ($this->compiled !== null) {
            return $this->compiled;
        }

        $compiled = [];

        foreach ($this->routesByMethod as $method => $routes) {
            $compiled[$method] = array_map(self::chunk(...), array_chunk($routes, self::CHUNK_SIZE));
        }

        return $this->compiled = $compiled;
    }

    /**
     * @param list<RouteEntry> $routes
     *
     * @return array{regex: non-empty-string, routes: list<RouteEntry>}
     */
    private static function chunk(array $routes): array
    {
        $alternatives = [];

        foreach ($routes as $index => $route) {
            $alternatives[] = $route->compiledPattern->fragment . '(*MARK:' . $index . ')';
        }

        return ['regex' => '#\\A(?|' . implode('|', $alternatives) . ')\\z#u', 'routes' => $routes];
    }
}
