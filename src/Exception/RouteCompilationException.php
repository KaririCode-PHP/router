<?php

declare(strict_types=1);

namespace KaririCode\Router\Exception;

/**
 * Route pattern compilation failed (invalid regex, duplicate params).
 *
 * ARFA 1.3 P2: thrown during registration, not at match time.
 *
 * Spec V4.0: final class with static factory.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc3986#section-3.3 URI path syntax errors
 */
final class RouteCompilationException extends RouterException
{
    public static function invalidPattern(string $pattern, string $reason): self
    {
        return new self("Invalid route pattern '{$pattern}': {$reason}");
    }

    public static function duplicateParameter(string $pattern, string $param): self
    {
        return new self("Duplicate parameter '{$param}' in route pattern '{$pattern}'");
    }

    public function __construct(
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 5140, $previous);
    }
}
