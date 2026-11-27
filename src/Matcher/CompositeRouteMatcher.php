<?php

declare(strict_types=1);

namespace KaririCode\Router\Matcher;

use KaririCode\Router\Contract\RouteMatcher;
use KaririCode\Router\Core\MatchResult;
use KaririCode\Router\Core\RouteEntry;

/**
 * The default strategy of RouteRegistry: StaticMatcher first, RegexMatcher
 * on a miss. Literal paths, often most of the traffic (/health, /login),
 * cost one hash lookup; only paths with parameters reach the expressions.
 *
 * Preconditions: a static route beats a pattern that also matches its path,
 * whatever the registration order: /users/new wins over /users/{id}.
 * allowedMethods() joins both tiers, because a path can be static for one
 * method and match a pattern for another.
 *
 * Thread-safety: written during boot, read afterwards.
 *
 * Side effects: add() hands the route to both tiers; each keeps the routes
 * it serves.
 *
 * Example:
 *
 *     $matcher = new CompositeRouteMatcher();
 *     $matcher->add(new RouteEntry(['GET'], '/users/new', 'users.create'));
 *     $matcher->add(new RouteEntry(['GET', 'DELETE'], '/users/{id}', 'users.item'));
 *     $matcher->match('GET', '/users/new')?->handler(); // 'users.create'
 *     $matcher->allowedMethods('/users/new');          // ['GET', 'DELETE']
 *
 * References: ADR-001 (composite matching strategy); SPEC-001 §5.
 *
 * @see https://nikic.github.io/2014/02/18/Fast-request-routing-using-regular-expressions.html
 */
final readonly class CompositeRouteMatcher implements RouteMatcher
{
    public function __construct(
        public StaticMatcher $staticMatcher = new StaticMatcher(),
        public RegexMatcher $regexMatcher = new RegexMatcher(),
    ) {}

    #[\Override]
    public function match(string $method, string $uri): ?MatchResult
    {
        return $this->staticMatcher->match($method, $uri) ?? $this->regexMatcher->match($method, $uri);
    }

    #[\Override]
    public function add(RouteEntry $route): void
    {
        $this->staticMatcher->add($route);
        $this->regexMatcher->add($route);
    }

    #[\Override]
    public function allowedMethods(string $uri): array
    {
        return array_values(array_unique([
            ...$this->staticMatcher->allowedMethods($uri),
            ...$this->regexMatcher->allowedMethods($uri),
        ]));
    }
}
