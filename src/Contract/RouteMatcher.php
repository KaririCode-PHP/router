<?php

declare(strict_types=1);

namespace KaririCode\Router\Contract;

use KaririCode\Router\Core\MatchResult;
use KaririCode\Router\Core\RouteEntry;

/**
 * One matching strategy behind the router: a hash map for static paths, a
 * compiled expression for patterns, a cached table, or a composition of
 * these. Swapping the strategy never touches RouteRegistry.
 *
 * Preconditions: match() returns null on a miss and never throws for one;
 * allowedMethods() then tells a 405 from a 404. Method strings arrive in
 * any case and are compared uppercase. A strategy may ignore routes it does
 * not serve (StaticMatcher skips patterns with placeholders).
 *
 * Thread-safety: like Router, written during boot and read afterwards.
 *
 * Side effects: add() changes the matcher's state; match() and
 * allowedMethods() may compile lazily but change nothing observable.
 *
 * Example:
 *
 *     $matcher = new CompositeRouteMatcher();
 *     $matcher->add(new RouteEntry(['GET'], '/users/{id:int}', 'users.show'));
 *     $matcher->match('GET', '/users/7')?->parameters->get('id'); // '7'
 *     $matcher->allowedMethods('/users/7');                      // ['GET']
 *
 * References: ADR-001 (composite matching); SPEC-001 §5.
 *
 * @see https://nikic.github.io/2014/02/18/Fast-request-routing-using-regular-expressions.html
 */
interface RouteMatcher
{
    public function match(string $method, string $uri): ?MatchResult;

    public function add(RouteEntry $route): void;

    /**
     * @return list<string> uppercase methods some route accepts for the path; empty when no route has it
     */
    public function allowedMethods(string $uri): array;
}
