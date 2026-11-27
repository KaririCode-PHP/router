<?php

declare(strict_types=1);

namespace KaririCode\Router\ValueObject;

use KaririCode\Router\Enum\RouteParameterType;
use KaririCode\Router\Exception\RouteCompilationException;

/**
 * A route path pattern, parsed and compiled once when the route is
 * defined, so matching never parses again. Literal text matches itself;
 * each placeholder matches one parameter:
 *
 *     /users/{id}           id, type string: [^/]+
 *     /users/{id:int}       id, type int: [0-9]+
 *     /posts/{slug:slug}    slug, lowercase words joined by "-"
 *     /items/{uuid:uuid}    uuid, 8-4-4-4-12 hexadecimal
 *     /files/{path:any}     path, the rest of the URI, "/" included
 *
 * The pattern compiles twice: $regex is anchored with named groups, for
 * matching this pattern alone; $fragment has positional groups and no
 * anchors, so RegexMatcher can join many fragments into one expression
 * without two routes clashing over a parameter name.
 *
 * Preconditions: a placeholder reads {name} or {name:type}; the name starts
 * with a letter or "_", appears once, and the type is one of
 * RouteParameterType. Any other brace is rejected at construction, so a
 * typo fails when the route is defined instead of never matching.
 * Patterns are matched against the raw path, without percent-decoding.
 *
 * Thread-safety: immutable once constructed.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     $pattern = new RoutePattern('/posts/{year:int}/{slug:slug}');
 *     $pattern->parameterNames;                         // ['year', 'slug']
 *     $pattern->matchAgainst('/posts/2026/hello-world'); // ['year' => '2026', 'slug' => 'hello-world']
 *     $pattern->matchAgainst('/posts/26a/hello');       // null
 *
 * References: SPEC-001 §3; RFC 3986 §3.3 (path); PCRE2 named and
 * positional groups; ADR-001 (two-tier matching).
 *
 * @see https://www.php.net/manual/en/regexp.reference.subpatterns.php
 */
final readonly class RoutePattern
{
    private const string PLACEHOLDER = '/\A\{([A-Za-z_][A-Za-z0-9_]*)(?::([^{}]*))?\}\z/';

    /**
     * Anchored at both ends (\A, \z: a trailing newline is not accepted), one named group per parameter: `#\A/users/(?P<id>[0-9]+)\z#u`.
     *
     * @var non-empty-string
     */
    public string $regex;

    /** Unanchored, with positional groups: `/users/([0-9]+)`. */
    public string $fragment;

    /** @var array<string, RouteParameterType> parameter name to type, in pattern order */
    public array $parameterTypes;

    /** @var list<string> parameter names, in pattern order */
    public array $parameterNames;

    /** True when the pattern has no placeholder: StaticMatcher serves it. */
    public bool $isStatic;

    public function __construct(
        public string $raw,
    ) {
        $types = [];
        $named = '';
        $positional = '';
        $parts = preg_split('/(\{[^{}]*\})/', $raw, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$raw];

        foreach ($parts as $index => $part) {
            if ($index % 2 === 0) {
                if (strpbrk($part, '{}') !== false) {
                    throw RouteCompilationException::invalidPattern($raw, 'a brace is not part of a {name} or {name:type} placeholder');
                }

                $named .= preg_quote($part, '#');
                $positional .= preg_quote($part, '#');

                continue;
            }

            [$name, $type] = $this->parsePlaceholder($part, $types);
            $types[$name] = $type;
            $named .= '(?P<' . $name . '>' . $type->pattern() . ')';
            $positional .= '(' . $type->pattern() . ')';
        }

        $this->regex = '#\\A' . $named . '\\z#u';
        $this->fragment = $positional;
        $this->parameterTypes = $types;
        $this->parameterNames = array_keys($types);
        $this->isStatic = $types === [];
    }

    /**
     * The parameters of a URI this pattern matches, or null when it does
     * not match.
     *
     * @return array<string, string>|null
     */
    public function matchAgainst(string $uri): ?array
    {
        if ($this->isStatic) {
            return $uri === $this->raw ? [] : null;
        }

        if (preg_match($this->regex, $uri, $matches) !== 1) {
            return null;
        }

        return $this->bind(array_map(
            static fn(string $name): string => $matches[$name] ?? '',
            $this->parameterNames,
        ));
    }

    /**
     * Pairs positional captures, in pattern order, with the parameter names.
     *
     * @param list<string> $captures
     *
     * @return array<string, string>
     */
    public function bind(array $captures): array
    {
        $parameters = [];

        foreach ($this->parameterNames as $position => $name) {
            $parameters[$name] = $captures[$position] ?? '';
        }

        return $parameters;
    }

    /**
     * @param array<string, RouteParameterType> $seen
     *
     * @return array{string, RouteParameterType}
     */
    private function parsePlaceholder(string $placeholder, array $seen): array
    {
        if (preg_match(self::PLACEHOLDER, $placeholder, $match) !== 1) {
            throw RouteCompilationException::invalidPattern($this->raw, 'a placeholder must read {name} or {name:type}, and a name starts with a letter or "_"');
        }

        $name = $match[1];
        $typeName = $match[2] ?? 'string';

        if (isset($seen[$name])) {
            throw RouteCompilationException::duplicateParameter($this->raw, $name);
        }

        $type = RouteParameterType::tryFromString($typeName)
            ?? throw RouteCompilationException::unknownParameterType($this->raw, $typeName);

        return [$name, $type];
    }
}
