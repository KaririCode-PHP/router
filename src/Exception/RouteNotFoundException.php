<?php

declare(strict_types=1);

namespace KaririCode\Router\Exception;

/**
 * No route matches the given method + URI pair.
 *
 * ARFA 1.3 P2: propagated from matcher to dispatcher.
 *
 * Spec V4.0: final class with static factory.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc9110#section-15.5.5 HTTP 404 Not Found
 */
final class RouteNotFoundException extends RouterException
{
    public static function forUri(string $method, string $uri): self
    {
        return new self($method, $uri);
    }

    public function __construct(
        string $method,
        string $uri,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            "No route matches {$method} {$uri}",
            5110,
            $previous,
        );
    }
}
