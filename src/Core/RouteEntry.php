<?php

declare(strict_types=1);

namespace KaririCode\Router\Core;

use Closure;
use KaririCode\Router\Exception\RouteCompilationException;
use KaririCode\Router\ValueObject\RoutePattern;

/**
 * One route: the methods and the path pattern it answers, the handler the
 * dispatcher should run, and what travels with it (name, middleware,
 * parameter defaults, free-form metadata). Methods are plain uppercase
 * strings, so any protocol can register routes: GET and POST, gRPC UNARY,
 * WebSocket SUBSCRIBE, CLI RUN.
 *
 * The handler is a definition, not a call: the router never invokes it. A
 * dispatcher resolves `'Controller::action'`, `[Controller::class,
 * 'action']` or a service id to something callable, which is why a plain
 * callable array is accepted even for instance methods. Host and scheme
 * are carried for the dispatcher too: no matcher reads them yet (ADR-005
 * proposes host and scheme matching).
 *
 * Preconditions: the constructor compiles the pattern and checks every
 * method against the RFC 9110 token grammar and the handler for emptiness,
 * throwing RouteCompilationException, so an entry that exists is valid.
 * The registry also requires the pattern to start with "/", which a group
 * entry gets from its prefix. Defaults fill parameters the path did not
 * provide; metadata must hold only scalars, null and arrays of those for
 * CachedMatcher to write the route out.
 *
 * Thread-safety: immutable; the with...() methods return new entries.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     $route = new RouteEntry(['GET'], '/users/{id:int}', [UserController::class, 'show'], name: 'users.show');
 *     $route->methods;              // ['GET']
 *     $route->matchUri('/users/7'); // ['id' => '7']
 *     $route->withPrefix('/api')->pattern; // '/api/users/{id:int}'
 *
 * References: ADR-002 (protocol-agnostic design); SPEC-001 §2; RFC 9110
 * §5.6.2 (token) and §9.1 (methods are case-sensitive; the router stores
 * them uppercased).
 *
 * @see https://www.rfc-editor.org/rfc/rfc9110#section-9.1
 */
final readonly class RouteEntry
{
    /** An RFC 9110 token that starts with a letter, uppercase. */
    private const string METHOD_TOKEN = "/\\A[A-Z][!#$%&'*+.^_`|~0-9A-Z-]*\\z/";

    /** The pattern, compiled when the entry was built. */
    public RoutePattern $compiledPattern;

    /** @var non-empty-list<string> uppercase, without repeats, in the order given */
    public array $methods;

    /**
     * @param list<string>                            $methods    Method strings of any protocol; stored uppercase
     * @param string|array{0: string, 1: string}|Closure $handler A definition the dispatcher resolves
     * @param list<string>                            $middleware Middleware definitions the dispatcher resolves, outermost first
     * @param array<string, string>                   $defaults   Values for parameters the path did not provide
     * @param array<string, mixed>                    $metadata   Free-form data for the dispatcher (rate limits, scopes)
     */
    public function __construct(
        array $methods,
        public string $pattern,
        public string|array|Closure $handler,
        public ?string $name = null,
        public array $middleware = [],
        public array $defaults = [],
        public array $metadata = [],
        public ?string $host = null,
        public ?string $scheme = null,
    ) {
        $this->compiledPattern = new RoutePattern($pattern);
        $this->methods = self::methodsOf($methods, $pattern);

        if (!self::isUsableHandler($handler)) {
            throw RouteCompilationException::invalidHandler($pattern);
        }
    }

    /**
     * The route's parameters for a URI its pattern matches, defaults
     * included, or null when the pattern does not match.
     *
     * @return array<string, string>|null
     */
    public function matchUri(string $uri): ?array
    {
        $parameters = $this->compiledPattern->matchAgainst($uri);

        return $parameters === null ? null : [...$this->defaults, ...$parameters];
    }

    /**
     * The route's parameters from positional captures in pattern order,
     * defaults included: what a combined expression of RegexMatcher yields.
     *
     * @param list<string> $captures
     *
     * @return array<string, string>
     */
    public function parametersFrom(array $captures): array
    {
        return [...$this->defaults, ...$this->compiledPattern->bind($captures)];
    }

    public function acceptsMethod(string $method): bool
    {
        return in_array(strtoupper($method), $this->methods, true);
    }

    /** A copy whose pattern starts with $prefix; a trailing "/" of the prefix is dropped. */
    public function withPrefix(string $prefix): self
    {
        return $this->copy(pattern: rtrim($prefix, '/') . $this->pattern);
    }

    /**
     * A copy that runs $middleware before the route's own.
     *
     * @param list<string> $middleware
     */
    public function withPrependedMiddleware(array $middleware): self
    {
        return $this->copy(middleware: [...$middleware, ...$this->middleware]);
    }

    /** A copy whose name starts with $prefix; an unnamed route stays unnamed. */
    public function withNamePrefix(string $prefix): self
    {
        return $this->name === null ? $this : $this->copy(name: $prefix . $this->name);
    }

    /**
     * @param list<string>|null $middleware
     */
    private function copy(?string $pattern = null, ?array $middleware = null, ?string $name = null): self
    {
        return new self(
            methods: $this->methods,
            pattern: $pattern ?? $this->pattern,
            handler: $this->handler,
            name: $name ?? $this->name,
            middleware: $middleware ?? $this->middleware,
            defaults: $this->defaults,
            metadata: $this->metadata,
            host: $this->host,
            scheme: $this->scheme,
        );
    }

    /**
     * @param list<string> $methods
     *
     * @return non-empty-list<string>
     */
    private static function methodsOf(array $methods, string $pattern): array
    {
        $normalized = array_values(array_unique(array_map(strtoupper(...), $methods)));

        if ($normalized === [] || !array_all($normalized, static fn(string $method): bool => preg_match(self::METHOD_TOKEN, $method) === 1)) {
            throw RouteCompilationException::invalidMethod($pattern);
        }

        return $normalized;
    }

    /**
     * @param string|array{0: string, 1: string}|Closure $handler
     */
    private static function isUsableHandler(string|array|Closure $handler): bool
    {
        return match (true) {
            $handler instanceof Closure => true,
            is_string($handler) => $handler !== '',
            default => $handler[0] !== '' && $handler[1] !== '',
        };
    }
}
