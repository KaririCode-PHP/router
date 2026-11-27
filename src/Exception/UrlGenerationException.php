<?php

declare(strict_types=1);

namespace KaririCode\Router\Exception;

/**
 * URL generation failed (missing param, unknown route, constraint violation).
 *
 * ARFA 1.3 P1: static factories produce immutable exception instances.
 *
 * Spec V4.0: final class with static factories.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc3986#section-5.3 URI reference resolution
 */
final class UrlGenerationException extends RouterException
{
    public static function missingParameter(string $routeName, string $param): self
    {
        return new self(
            "Missing required parameter '{$param}' for route '{$routeName}'",
            5120,
        );
    }

    public static function unknownRoute(string $routeName): self
    {
        return new self(
            "Cannot generate URL for unknown route '{$routeName}'",
            5121,
        );
    }

    public static function constraintViolation(string $routeName, string $param, string $value): self
    {
        return new self(
            "Value '{$value}' for parameter '{$param}' violates constraint on route '{$routeName}'",
            5122,
        );
    }
}
