<?php

declare(strict_types=1);

namespace KaririCode\Router\Contract;

use KaririCode\Router\Exception\RouteNotFoundException;
use KaririCode\Router\Exception\UrlGenerationException;

/**
 * Builds the path of a named route from parameter values: reverse routing,
 * so links follow the route definitions instead of repeating them.
 *
 * Preconditions: every placeholder needs a value or a route default, and
 * every value must fit the placeholder's type. Values are percent-encoded
 * per path segment; parameters that are not placeholders join the query
 * string, as do the entries of $query.
 *
 * Thread-safety: generation reads the routes and keeps no state.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     $generator->generate('users.show', ['id' => 42]);               // '/users/42'
 *     $generator->generate('users.show', ['id' => 42], ['tab' => 'x']); // '/users/42?tab=x'
 *
 * References: RFC 3986 §2.1 (percent-encoding) and §5.3 (recomposition);
 * SPEC-001 §6.
 *
 * @see https://www.rfc-editor.org/rfc/rfc3986#section-5.3
 */
interface UrlGenerator
{
    /**
     * @param array<string, string|int> $parameters
     * @param array<string, string>     $query
     *
     * @throws RouteNotFoundException when no route has the name
     * @throws UrlGenerationException when a value is missing or breaks its type
     */
    public function generate(string $routeName, array $parameters = [], array $query = []): string;
}
