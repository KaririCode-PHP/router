<?php

declare(strict_types=1);

namespace KaririCode\Router\Core;

/**
 * The two strings the router matches on, taken from a protocol's native
 * request by a RequestNormalizer, plus that request, kept so a handler can
 * still reach headers, body or stream state the router never needed.
 *
 * Preconditions: $method is the uppercase method or operation (GET, UNARY,
 * SUBSCRIBE, RUN); $uri is the path only, starting with "/", without query
 * string or fragment.
 *
 * Thread-safety: immutable, though $original may itself be mutable.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     $request = $normalizer->normalize($serverRequest);
 *     $result = $router->match($request->method, $request->uri);
 *
 * References: ADR-002 (protocol-agnostic design); RFC 3986 §3 (URI
 * components).
 *
 * @see https://www.rfc-editor.org/rfc/rfc3986#section-3
 */
final readonly class NormalizedRequest
{
    public function __construct(
        public string $method,
        public string $uri,
        public ?object $original = null,
    ) {}
}
