<?php

declare(strict_types=1);

namespace KaririCode\Router\Contract;

use KaririCode\Router\Core\NormalizedRequest;

/**
 * Extracts the (method, uri) pair from a protocol-specific request object.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): each protocol package (HTTP,
 * WebSocket, SSE, gRPC) provides its own normalizer. Adding a new
 * protocol requires exactly one new class — no existing file changes.
 * Spec V4.0 §1.3: interface without "Interface" suffix.
 *
 * Each protocol (HTTP, gRPC, WebSocket, GraphQL, SSE, CLI, or any
 * future protocol) implements this contract to participate in routing.
 *
 * Adding a new protocol requires exactly one new class. No existing
 * file is modified. No enum is extended. No switch is updated.
 *
 * The router core never depends on concrete normalizers — it accepts
 * raw strings. Normalizers exist so protocol packages can provide a
 * standard bridge without each application writing ad-hoc extraction.
 *
 * @see \KaririCode\Router\Core\NormalizedRequest
 * @see https://datatracker.ietf.org/doc/html/rfc3986#section-3 URI component extraction
 */
interface RequestNormalizer
{
    /**
     * Whether this normalizer can handle the given request object.
     *
     * Used for automatic protocol detection when multiple normalizers
     * are registered and the caller does not specify which one to use.
     */
    public function canNormalize(object $request): bool;

    /**
     * Extracts the method string and URI path from the native request.
     *
     * The returned NormalizedRequest carries the original request object
     * so handlers can access protocol-specific APIs (headers, body,
     * streaming state, connection metadata, etc.) without the router
     * needing to understand any of it.
     */
    public function normalize(object $request): NormalizedRequest;
}
