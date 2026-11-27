<?php

declare(strict_types=1);

namespace KaririCode\Router\Exception;

use KaririCode\Exception\ValidationException;

/**
 * The parameters given to RouteUrlGenerator::generate() cannot build a URI
 * for the route: a placeholder has no value and no default (5020), or a
 * value breaks the placeholder's type (5022). Corrected parameters succeed,
 * which makes it a Validation failure. An unknown route name is
 * RouteNotFoundException::named() instead.
 *
 * Preconditions: the message names the route and the parameter, both
 * identifiers the application chose, and never the rejected value, which
 * often comes from user data (errata E-047).
 *
 * Thread-safety: an exception belongs to the call that threw it.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     $generator->generate('users.show', ['id' => 'abc']);
 *     // UrlGenerationException [RO_5022]: the value for "id" breaks its {id:int} constraint
 *
 * References: KaririCode Spec V4.3.3 §8.4; RFC 3986 §5.3 (component
 * recomposition); ADR-007.
 *
 * @see https://www.rfc-editor.org/rfc/rfc3986#section-5.3
 */
final class UrlGenerationException extends ValidationException
{
    public static function missingParameter(string $routeName, string $parameter): self
    {
        return new self(
            RouterCode::MissingParameter,
            sprintf('Route %s needs a value for the parameter %s.', self::quote($routeName), self::quote($parameter)),
        );
    }

    public static function constraintViolation(string $routeName, string $parameter): self
    {
        return new self(
            RouterCode::ConstraintViolation,
            sprintf('The value for the parameter %s of route %s breaks its type.', self::quote($parameter), self::quote($routeName)),
        );
    }
}
