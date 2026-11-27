<?php

declare(strict_types=1);

namespace KaririCode\Router\Exception;

use KaririCode\Exception\ConfigurationException;

/**
 * A route definition cannot be compiled, either into a matcher or into the
 * cached route table. These are mistakes in the application's routes, so
 * they surface when a route is registered or the cache is warmed, never on
 * a request (fail fast, Spec V4.3.3 §8.5):
 *
 *     5000–5004  the pattern, its placeholders, its methods or its handler
 *     5005–5006  a second route with the same method and pattern, or name
 *     5040–5042  a route, matcher or cache key the cached table cannot hold
 *
 * Preconditions: the message names the route by its pattern or name and the
 * rule it breaks. Patterns and names are identifiers the application
 * chose, not secrets or user data, and quote() makes them safe to log.
 *
 * Thread-safety: an exception belongs to the call that threw it.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     $router->get('/users/{id:integer}', $handler);
 *     // RouteCompilationException [RO_5002]: "integer" is not a parameter type
 *
 * References: KaririCode Spec V4.3.3 §8.4 and §8.5; SPEC-001 §3 (pattern
 * syntax); RFC 9110 §5.6.2 (tokens); ADR-007.
 *
 * @see https://www.rfc-editor.org/rfc/rfc9110#section-5.6.2
 */
final class RouteCompilationException extends ConfigurationException
{
    public static function invalidPattern(string $pattern, string $rule): self
    {
        return new self(RouterCode::InvalidPattern, sprintf('Route pattern %s is invalid: %s.', self::quote($pattern), $rule));
    }

    public static function duplicateParameter(string $pattern, string $parameter): self
    {
        return new self(
            RouterCode::DuplicateParameter,
            sprintf('Route pattern %s names the parameter %s twice.', self::quote($pattern), self::quote($parameter)),
        );
    }

    public static function unknownParameterType(string $pattern, string $type): self
    {
        return new self(
            RouterCode::UnknownParameterType,
            sprintf('Route pattern %s uses %s, which is not one of int, string, slug, uuid or any.', self::quote($pattern), self::quote($type)),
        );
    }

    public static function invalidMethod(string $pattern): self
    {
        return new self(
            RouterCode::InvalidMethod,
            sprintf('Route %s needs one or more methods, each an RFC 9110 token.', self::quote($pattern)),
        );
    }

    public static function invalidHandler(string $pattern): self
    {
        return new self(
            RouterCode::InvalidHandler,
            sprintf('Route %s needs a handler: a non-empty string, a [class, method] pair of non-empty strings or a Closure.', self::quote($pattern)),
        );
    }

    public static function duplicateRoute(string $method, string $pattern): self
    {
        return new self(
            RouterCode::DuplicateRoute,
            sprintf('A route for %s %s is already registered.', $method, self::quote($pattern)),
        );
    }

    public static function duplicateRouteName(string $name): self
    {
        return new self(RouterCode::DuplicateRouteName, sprintf('A route named %s is already registered.', self::quote($name)));
    }

    public static function uncacheableRoute(string $pattern, string $reason): self
    {
        return new self(
            RouterCode::UncacheableRoute,
            sprintf('Route %s cannot be written to the route cache: %s.', self::quote($pattern), $reason),
        );
    }

    public static function uncacheableMatcher(): self
    {
        return new self(
            RouterCode::UncacheableMatcher,
            'CachedMatcher can compile the table only from a CompositeRouteMatcher.',
        );
    }

    public static function invalidCacheKey(): self
    {
        return new self(
            RouterCode::InvalidCacheKey,
            'A route cache key needs 1 to 64 characters from A-Z, a-z, 0-9, "_" and ".", the set every PSR-16 cache accepts.',
        );
    }
}
