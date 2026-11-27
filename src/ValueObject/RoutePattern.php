<?php

declare(strict_types=1);

namespace KaririCode\Router\ValueObject;

use KaririCode\Router\Enum\RouteParameterType;

/**
 * Compiled route pattern with extracted parameter metadata.
 *
 * ARFA 1.3 P1 (Immutable State Transformation): compilation happens
 * once at registration time; the regex and parameter map are reused
 * on every match.
 *
 * Spec V4.0: final readonly class. Compilation is eager (constructor).
 *
 * @see https://datatracker.ietf.org/doc/html/rfc3986#section-3.3 URI path pattern syntax
 *
 * Pattern syntax:
 *   /users/{id}            → parameter "id", type String
 *   /users/{id:int}        → parameter "id", type Int
 *   /posts/{slug:slug}     → parameter "slug", type Slug
 *   /files/{path:any}      → parameter "path", greedy match
 *   /docs/{uuid:uuid}      → parameter "uuid", UUID v4 format
 */
final readonly class RoutePattern
{
    public string $regex;

    /** @var array<string, RouteParameterType> */
    public array $parameterTypes;

    /** @var list<string> */
    public array $parameterNames;

    public bool $isStatic;

    public function __construct(
        public string $raw,
    ) {
        $paramTypes = [];
        $paramNames = [];

        // Split pattern into static segments and parameter placeholders.
        $segments = preg_split('/(\{[^}]+\})/', $raw, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $regexParts = [];

        foreach ($segments ?: [$raw] as $segment) {
            if (preg_match('/^\{(\w+)(?::(\w+))?\}$/', $segment, $m)) {
                $name = $m[1];
                $type = RouteParameterType::fromString($m[2] ?? 'string');
                $paramTypes[$name] = $type;
                $paramNames[] = $name;
                $regexParts[] = '(?P<' . $name . '>' . $type->pattern() . ')';
            } else {
                $regexParts[] = preg_quote($segment, '#');
            }
        }

        $this->regex = '#^' . implode('', $regexParts) . '$#u';
        $this->parameterTypes = $paramTypes;
        $this->parameterNames = $paramNames;
        $this->isStatic = $paramNames === [];
    }

    /**
     * @return array<string, string>|null Extracted parameters or null on mismatch.
     */
    public function matchAgainst(string $uri): ?array
    {
        if ($this->isStatic) {
            return $this->raw === $uri ? [] : null;
        }

        if (!preg_match($this->regex, $uri, $matches)) {
            return null;
        }

        $params = [];
        foreach ($this->parameterNames as $name) {
            if (isset($matches[$name]) && $matches[$name] !== '') {
                $params[$name] = $matches[$name];
            }
        }

        return $params;
    }

    /**
     * @param array<string, string|int> $parameters
     */
    public function reverseUri(array $parameters): string
    {
        return preg_replace_callback(
            '/\{(\w+)(?::(\w+))?\}/',
            static function (array $match) use ($parameters): string {
                $name = $match[1];
                if (!isset($parameters[$name])) {
                    throw new \InvalidArgumentException("Missing parameter: {$name}");
                }
                return (string) $parameters[$name];
            },
            $this->raw,
        ) ?? $this->raw;
    }
}
