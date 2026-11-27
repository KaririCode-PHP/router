<?php

declare(strict_types=1);

namespace KaririCode\Router\Enum;

/**
 * Route parameter constraint types for pattern compilation.
 *
 * ARFA 1.3 P1 (Immutable State Transformation): pure enum, each
 * case carries an immutable PCRE fragment.
 * Spec V4.0: pure enum with pattern() method.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc3986#section-3.3 URI path segment constraints
 *
 * Each variant carries a PCRE fragment used during Radix Tree node
 * compilation. Custom patterns override the default fragment.
 */
enum RouteParameterType
{
    case Int;
    case String;
    case Slug;
    case Uuid;
    case Any;

    public function pattern(): string
    {
        return match ($this) {
            self::Int => '[0-9]+',
            self::String => '[^/]+',
            self::Slug => '[a-z0-9]+(?:-[a-z0-9]+)*',
            self::Uuid => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}',
            self::Any => '.+',
        };
    }

    public static function fromString(string $type): self
    {
        return match (strtolower(trim($type))) {
            'int', 'integer' => self::Int,
            'string' => self::String,
            'slug' => self::Slug,
            'uuid' => self::Uuid,
            'any' => self::Any,
            default => self::String,
        };
    }
}
