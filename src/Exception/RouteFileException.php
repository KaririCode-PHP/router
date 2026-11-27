<?php

declare(strict_types=1);

namespace KaririCode\Router\Exception;

use KaririCode\Exception\ConfigurationException;

/**
 * A route file or a route definition array that ArrayRouteLoader cannot
 * read: the file is missing (5050), it does not return an array (5051), or
 * an entry does not have the shape the loader documents (5052). The
 * application's configuration is wrong, so it fails at boot.
 *
 * Preconditions: the message names the file and the entry's position
 * (`entry 3 of group "/admin"`), not the entry's values.
 *
 * Thread-safety: an exception belongs to the call that threw it.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     $routes = (new ArrayRouteLoader())->loadFile(__DIR__ . '/config/routes.php');
 *     // RouteFileException [RO_5050] when the file does not exist
 *
 * References: KaririCode Spec V4.3.3 §8.4 and §8.5; ADR-007.
 *
 * @see https://www.php.net/manual/en/function.require.php
 */
final class RouteFileException extends ConfigurationException
{
    public static function missing(string $path): self
    {
        return new self(RouterCode::RouteFileMissing, sprintf('Route file %s does not exist.', self::quote($path)));
    }

    public static function notAnArray(string $path): self
    {
        return new self(RouterCode::RouteFileInvalid, sprintf('Route file %s must return an array of route definitions.', self::quote($path)));
    }

    public static function invalidDefinition(string $position, string $rule): self
    {
        return new self(RouterCode::InvalidRouteDefinition, sprintf('Route definition %s is invalid: %s.', $position, $rule));
    }
}
