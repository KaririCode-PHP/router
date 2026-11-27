<?php

declare(strict_types=1);

namespace KaririCode\Router\Generator;

use KaririCode\Router\Contract\UrlGenerator;
use KaririCode\Router\Core\RouteEntry;
use KaririCode\Router\Core\RouteRegistry;
use KaririCode\Router\Enum\RouteParameterType;
use KaririCode\Router\Exception\RouteNotFoundException;
use KaririCode\Router\Exception\UrlGenerationException;

/**
 * Builds the path of a named route from the routes of a RouteRegistry, so
 * links and redirects follow the route definitions.
 *
 * Each placeholder takes its value from $parameters, else from the route's
 * defaults; the value must fit the placeholder's type and is
 * percent-encoded. An {x:any} value keeps its "/" separators and encodes
 * each segment, so '/docs/2026/report.pdf' stays readable instead of
 * becoming docs%2F2026 (code review BUG-005). Parameters that are not
 * placeholders join the query string, then $query, encoded per RFC 3986.
 *
 * Preconditions: the route is registered under that name. withBase()
 * prepends a scheme and host, or a base path, without its trailing "/".
 *
 * Thread-safety: immutable; withBase() returns a new generator.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     $generator = new RouteUrlGenerator($router);
 *     $generator->generate('users.show', ['id' => 42]);                  // '/users/42'
 *     $generator->generate('files.show', ['path' => 'a b/c.txt']);       // '/files/a%20b/c.txt'
 *     $generator->withBase('https://api.example.com')->generate('users.index', [], ['page' => '2']);
 *     // 'https://api.example.com/users?page=2'
 *
 * References: RFC 3986 §2.1, §3.3 and §5.3; SPEC-001 §6.
 *
 * @see https://www.rfc-editor.org/rfc/rfc3986#section-2.1
 */
final readonly class RouteUrlGenerator implements UrlGenerator
{
    private const string PLACEHOLDER = '/\{(\w+)(?::\w+)?\}/';

    public function __construct(
        private RouteRegistry $registry,
        private string $baseUri = '',
    ) {}

    /** A generator that prepends $baseUri, without its trailing "/". */
    public function withBase(string $baseUri): self
    {
        return new self($this->registry, rtrim($baseUri, '/'));
    }

    #[\Override]
    public function generate(string $routeName, array $parameters = [], array $query = []): string
    {
        $route = $this->registry->namedRoute($routeName) ?? throw RouteNotFoundException::named($routeName);
        $path = (string) preg_replace_callback(
            self::PLACEHOLDER,
            static fn(array $match): string => self::segment($route, $routeName, $match[1], $parameters),
            $route->pattern,
        );
        $queryString = http_build_query([...array_diff_key($parameters, $route->compiledPattern->parameterTypes), ...$query], '', '&', PHP_QUERY_RFC3986);

        return $this->baseUri . $path . ($queryString === '' ? '' : '?' . $queryString);
    }

    /**
     * @param array<string, string|int> $parameters
     */
    private static function segment(RouteEntry $route, string $routeName, string $name, array $parameters): string
    {
        $value = (string) ($parameters[$name] ?? $route->defaults[$name] ?? throw UrlGenerationException::missingParameter($routeName, $name));
        $type = $route->compiledPattern->parameterTypes[$name] ?? RouteParameterType::String;

        if (preg_match('#\A(?:' . $type->pattern() . ')\z#u', $value) !== 1) {
            throw UrlGenerationException::constraintViolation($routeName, $name);
        }

        return $type === RouteParameterType::Any
            ? implode('/', array_map(rawurlencode(...), explode('/', $value)))
            : rawurlencode($value);
    }
}
