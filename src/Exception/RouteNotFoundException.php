<?php

declare(strict_types=1);

namespace KaririCode\Router\Exception;

use KaririCode\Exception\NotFoundException;

/**
 * A lookup by identity found no route: no route accepts the request path
 * (5010), or URL generation asked for a route name nobody registered
 * (5021). It belongs to the NotFound family of kariricode/contract, so an
 * edge that knows nothing of the router still answers 404.
 *
 * Preconditions: the matching message never repeats the request's method or
 * path, which the client controls and may carry personal data (errata
 * E-047); the caller already holds both. A route name is an identifier the
 * application chose, so named() quotes it.
 *
 * Thread-safety: an exception belongs to the call that threw it.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     try {
 *         $result = $router->match($method, $path);
 *     } catch (RouteNotFoundException) {
 *         return $responses->notFound();
 *     }
 *
 * References: KaririCode Spec V4.3.3 §8.1 (absent resource on get) and
 * §8.4; RFC 9110 §15.5.5; ADR-007.
 *
 * @see https://www.rfc-editor.org/rfc/rfc9110#section-15.5.5
 */
final class RouteNotFoundException extends NotFoundException
{
    public static function forRequest(): self
    {
        return new self(RouterCode::RouteNotFound, 'No route matches the request method and path.');
    }

    public static function named(string $name): self
    {
        return new self(RouterCode::UnknownRouteName, sprintf('No route is named %s.', self::quote($name)));
    }
}
