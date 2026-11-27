<?php

declare(strict_types=1);

namespace KaririCode\Router\Enum;

/**
 * HTTP request methods as defined in RFC 9110 §9.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): prefer raw uppercase strings
 * ('GET', 'POST') for protocol-agnostic routing.
 *
 * Spec V4.0: pure enum, backed by string for wire-format compatibility.
 *
 * @deprecated Since V4.1. Use raw uppercase strings directly with
 *             RouteEntry and RouteRegistry::route(). This enum is
 *             retained for backward compatibility with attribute-based
 *             routing (#[Route(method: HttpMethod::Get)]).
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7231#section-4
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

    // ── Factory ──────────────────────────────────────────────────────────

    public static function fromString(string $method): self
    {
        return self::from(strtoupper(trim($method)));
    }

    // ── Wire Format ──────────────────────────────────────────────────────

    public function label(): string
    {
        return $this->value;
    }

    public function isSafe(): bool
    {
        return match ($this) {
            self::Get, self::Head, self::Options => true,
            default => false,
        };
    }

    public function isIdempotent(): bool
    {
        return match ($this) {
            self::Get, self::Head, self::Options,
            self::Put, self::Delete => true,
            default => false,
        };
    }
}
