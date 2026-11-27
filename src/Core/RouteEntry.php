<?php

declare(strict_types=1);

namespace KaririCode\Router\Core;

use KaririCode\Router\ValueObject\RoutePattern;

/**
 * Immutable definition of a single route (ARFA 1.3 P1).
 *
 * Spec V4.0: final readonly class. Pattern compilation is eager
 * (happens at construction) so match-time has zero regex setup cost.
 *
 * A route binds one or more methods + a URI pattern to a handler
 * (callable, controller::method string, or [class, method] pair) with
 * optional middleware, name, defaults, and metadata.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): methods are stored as uppercase
 * strings so any protocol can register routes — HTTP verbs (GET, POST),
 * gRPC operations (UNARY, SERVER_STREAMING), WebSocket events (SUBSCRIBE),
 * CLI commands (RUN), GraphQL operations (QUERY, MUTATION), and custom
 * protocols all pass through the same data structure.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc3986#section-3.3 URI path pattern definition
 */
final readonly class RouteEntry
{
    public RoutePattern $compiledPattern;

    /**
     * @var list<string> Uppercase method/operation strings (protocol-independent).
     */
    public array $methods;

    /**
     * @param list<string>             $methods    Uppercase method strings (e.g. ['GET','HEAD'], ['UNARY'], ['SUBSCRIBE'])
     * @param string|callable|array{0:class-string,1:string} $handler
     * @param list<string|class-string> $middleware Class-strings resolved at dispatch time
     * @param array<string, string>    $defaults   Default values for optional params
     * @param array<string, mixed>     $metadata   Arbitrary data (rate-limit config, etc.)
     */
    public function __construct(
        array $methods,
        public string $pattern,
        public string|callable|array $handler,
        public ?string $name = null,
        public array $middleware = [],
        public array $defaults = [],
        public array $metadata = [],
        public ?string $host = null,
        public ?string $scheme = null,
    ) {
        $this->methods = array_values(array_map(strtoupper(...), $methods));
        $this->compiledPattern = new RoutePattern($pattern);
    }

    /**
     * @return array<string, string>|null Matched parameters or null.
     */
    public function matchUri(string $uri): ?array
    {
        $params = $this->compiledPattern->matchAgainst($uri);

        if ($params === null) {
            return null;
        }

        foreach ($this->defaults as $key => $default) {
            $params[$key] ??= $default;
        }

        return $params;
    }

    public function acceptsMethod(string $method): bool
    {
        return in_array(strtoupper($method), $this->methods, true);
    }

    /**
     * @param list<string|class-string> $extraMiddleware
     */
    public function withPrependedMiddleware(array $extraMiddleware): self
    {
        return new self(
            methods: $this->methods,
            pattern: $this->pattern,
            handler: $this->handler,
            name: $this->name,
            middleware: [...$extraMiddleware, ...$this->middleware],
            defaults: $this->defaults,
            metadata: $this->metadata,
            host: $this->host,
            scheme: $this->scheme,
        );
    }

    public function withPrefix(string $prefix): self
    {
        $normalizedPrefix = rtrim($prefix, '/');
        return new self(
            methods: $this->methods,
            pattern: $normalizedPrefix . $this->pattern,
            handler: $this->handler,
            name: $this->name,
            middleware: $this->middleware,
            defaults: $this->defaults,
            metadata: $this->metadata,
            host: $this->host,
            scheme: $this->scheme,
        );
    }
}
