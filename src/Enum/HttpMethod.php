<?php

declare(strict_types=1);

namespace KaririCode\Router\Enum;

/**
 * The seven HTTP methods of RFC 9110 §9 that #[Route] accepted before the
 * router took plain method strings. It stays for attribute code written
 * against 4.0; new code passes strings, which any protocol can use (gRPC
 * UNARY, WebSocket SUBSCRIBE, CLI RUN).
 *
 * Preconditions: backed by the uppercase method name, the form RouteEntry
 * stores; the router never converts a method string back to this enum.
 *
 * Thread-safety: enum cases are immutable.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     #[Route('/users', method: HttpMethod::Post)] // same route as method: 'POST'
 *
 * References: RFC 9110 §9 (methods) and §9.2 (safe and idempotent methods);
 * ADR-002 (protocol-agnostic design).
 *
 * @deprecated since 4.1: pass method strings ('GET', 'POST'); removed in 5.0.
 *
 * @see https://www.rfc-editor.org/rfc/rfc9110#section-9
 */
enum HttpMethod: string
{
    case Get = 'GET';
    case Post = 'POST';
    case Put = 'PUT';
    case Patch = 'PATCH';
    case Delete = 'DELETE';
    case Head = 'HEAD';
    case Options = 'OPTIONS';

    /** RFC 9110 §9.2.1: the method is essentially read-only. */
    public function isSafe(): bool
    {
        return match ($this) {
            self::Get, self::Head, self::Options => true,
            default => false,
        };
    }

    /** RFC 9110 §9.2.2: repeating the request has the effect of sending it once. */
    public function isIdempotent(): bool
    {
        return match ($this) {
            self::Post, self::Patch => false,
            default => true,
        };
    }
}
