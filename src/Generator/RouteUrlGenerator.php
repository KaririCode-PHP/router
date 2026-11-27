<?php

declare(strict_types=1);

namespace KaririCode\Router\Generator;

use KaririCode\Router\Contract\UrlGenerator as UrlGeneratorContract;
use KaririCode\Router\Core\RouteRegistry;
use KaririCode\Router\Exception\UrlGenerationException;

/**
 * Generates URIs from named routes.
 *
 * ARFA 1.3 P1: withBase() returns a new instance (wither pattern).
 *
 * Spec V4.0: final class.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc3986#section-5.3 URI reference resolution
 *
 * Replaces parameter placeholders in the route pattern with provided
 * values, appends unused parameters as query string, and validates
 * parameter constraints.
 */
final class RouteUrlGenerator implements UrlGeneratorContract
{
    private ?string $baseUri = null;

    public function __construct(
        private readonly RouteRegistry $registry,
    ) {
    }

    public function withBase(string $baseUri): self
    {
        $clone = clone $this;
        $clone->baseUri = rtrim($baseUri, '/');
        return $clone;
    }

    public function generate(string $routeName, array $parameters = [], array $query = []): string
    {
        $route = $this->registry->namedRoute($routeName);

        if ($route === null) {
            throw UrlGenerationException::unknownRoute($routeName);
        }

        $pattern = $route->compiledPattern;
        $uri = $pattern->raw;
        $usedParams = [];

        // Replace each placeholder
        $uri = preg_replace_callback(
            '/\{(\w+)(?::(\w+))?\}/',
            function (array $match) use ($routeName, $parameters, $route, &$usedParams): string {
                $name = $match[1];
                $usedParams[] = $name;

                $value = $parameters[$name] ?? $route->defaults[$name] ?? null;

                if ($value === null) {
                    throw UrlGenerationException::missingParameter($routeName, $name);
                }

                $stringValue = (string) $value;

                    $type = $route->compiledPattern->parameterTypes[$name] ?? null;
                if ($type !== null && !preg_match('#^' . $type->pattern() . '$#u', $stringValue)) {
                    throw UrlGenerationException::constraintViolation($routeName, $name, $stringValue);
                }

                return rawurlencode($stringValue);
            },
            $uri,
        ) ?? $uri;

        // Append unused parameters + explicit query as query string
        $extraParams = array_diff_key($parameters, array_flip($usedParams));
        $allQuery = [...array_map('strval', $extraParams), ...$query];

        if ($allQuery !== []) {
            $uri .= '?' . http_build_query($allQuery, '', '&', PHP_QUERY_RFC3986);
        }

        if ($this->baseUri !== null) {
            $uri = $this->baseUri . $uri;
        }

        return $uri;
    }
}
