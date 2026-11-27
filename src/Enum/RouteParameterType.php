<?php

declare(strict_types=1);

namespace KaririCode\Router\Enum;

/**
 * The type a route placeholder declares after its colon, `{id:int}`, and
 * the PCRE fragment it compiles to. A path segment that does not fit the
 * type is a miss, so malformed URLs get a 404 from the router instead of
 * reaching the handler. A placeholder without a type is String.
 *
 * Preconditions: no fragment holds a capturing group, because RegexMatcher
 * maps captures to parameters by position; Slug uses a non-capturing group.
 * Any is the only type that crosses "/" and matches greedily, so it belongs
 * at the end of a pattern. No type matches a control character: RFC 3986
 * paths carry them percent-encoded, so a raw one is malformed input. Uuid
 * accepts both letter cases (RFC 9562 §4).
 *
 * Thread-safety: enum cases are immutable.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     RouteParameterType::tryFromString('int')?->pattern(); // '[0-9]+'
 *     RouteParameterType::tryFromString('integer');         // RouteParameterType::Int
 *     RouteParameterType::tryFromString('date');            // null: RoutePattern rejects it
 *
 * References: SPEC-001 §3 (pattern syntax); RFC 3986 §3.3 (path segments);
 * RFC 9562 §4 (UUID format); ADR-004 (custom constraints, proposed).
 *
 * @see https://www.rfc-editor.org/rfc/rfc3986#section-3.3
 */
enum RouteParameterType
{
    case Int;
    case String;
    case Slug;
    case Uuid;
    case Any;

    /**
     * The type a placeholder names, case-insensitively, or null when the
     * name is not a type ("integer" is an alias of int).
     */
    public static function tryFromString(string $type): ?self
    {
        return match (strtolower($type)) {
            'int', 'integer' => self::Int,
            'string' => self::String,
            'slug' => self::Slug,
            'uuid' => self::Uuid,
            'any' => self::Any,
            default => null,
        };
    }

    /** The PCRE fragment, without anchors or capturing groups. */
    public function pattern(): string
    {
        return match ($this) {
            self::Int => '[0-9]+',
            self::String => '[^/\x00-\x1F\x7F]+',
            self::Slug => '[a-z0-9]+(?:-[a-z0-9]+)*',
            self::Uuid => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}',
            self::Any => '[^\x00-\x1F\x7F]+',
        };
    }
}
