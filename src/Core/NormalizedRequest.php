<?php

declare(strict_types=1);

namespace KaririCode\Router\Core;

/**
 * Protocol-agnostic request produced by a {@see \KaririCode\Router\Contract\RequestNormalizer}.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): the two strings (method, uri)
 * are the only data the router needs. All protocol-specific state
 * stays in the original request object.
 * Spec V4.0: final readonly value object.
 *
 * Carries only the two strings the router needs for matching plus
 * a reference to the original protocol-specific request for handler access.
 *
 * Intentionally thin: headers, body, attributes, and protocol semantics
 * stay in the original request object where they belong. The router
 * does not need to understand them.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc3986#section-3 URI components
 */
final readonly class NormalizedRequest
{
    /**
     * @param string      $method   Uppercase method string (GET, UNARY, QUERY, SUBSCRIBE, RUN, …)
     * @param string      $uri      URI path (always starts with /)
     * @param object|null $original Native protocol request preserved for handler-level access
     */
    public function __construct(
        public string $method,
        public string $uri,
        public ?object $original = null,
    ) {
    }
}
