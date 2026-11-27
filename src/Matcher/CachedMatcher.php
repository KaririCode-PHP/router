<?php

declare(strict_types=1);

namespace KaririCode\Router\Matcher;

use Closure;
use KaririCode\Router\Contract\RouteCacheStore;
use KaririCode\Router\Contract\RouteMatcher;
use KaririCode\Router\Core\MatchResult;
use KaririCode\Router\Core\RouteEntry;
use KaririCode\Router\Exception\RouteCacheException;
use KaririCode\Router\Exception\RouteCompilationException;

/**
 * Serves matches from a compiled route table kept in a RouteCacheStore, so
 * a process that finds the table neither compiles patterns nor builds the
 * combined expressions. warm() compiles the table from a
 * CompositeRouteMatcher, typically in a deploy step; until a table exists,
 * every call goes to the inner matcher.
 *
 * The table holds the static map and the combined expressions with the
 * routes as plain arrays, plus a checksum of those routes. When the inner
 * matcher has routes of its own, the table is used only if its checksum
 * matches them, so a table left behind by an older deploy is ignored
 * instead of serving old routes. An application that skips registering
 * routes when isCached() is true gets the full saving.
 *
 * Preconditions: warm() needs a CompositeRouteMatcher as the inner matcher,
 * handlers that are strings or [class, method] pairs (a Closure cannot be
 * written out) and metadata of scalars, null and arrays; it throws
 * RouteCompilationException otherwise. A matched route is rebuilt from the
 * table, which compiles that one pattern again.
 *
 * Thread-safety: the store replaces the table atomically; an instance loads
 * it once and keeps it for its lifetime.
 *
 * Side effects: warm() and clear() write to the store; match() may read it
 * once.
 *
 * Example:
 *
 *     $matcher = new CachedMatcher(new CompositeRouteMatcher(), new PhpFileStore($cacheDir . '/routes.php'));
 *     $router = new RouteRegistry($matcher);
 *
 *     if (!$matcher->isCached()) {
 *         require __DIR__ . '/routes.php'; // registers on $router
 *         $matcher->warm();
 *     }
 *
 * References: ADR-003 (route caching); SPEC-001 §5.3; the cached
 * dispatchers of nikic/FastRoute and Symfony's CompiledUrlMatcher.
 *
 * @see https://www.php.net/manual/en/book.opcache.php
 *
 * @psalm-type CachedRoute = array{methods: non-empty-list<string>, pattern: string, handler: string|array{0: string, 1: string}, name: ?string, middleware: list<string>, defaults: array<string, string>, metadata: array<string, mixed>, host: ?string, scheme: ?string}
 * @psalm-type CompiledTable = array{format: int, checksum: string, static: array<string, array<string, CachedRoute>>, dynamic: array<string, list<array{regex: non-empty-string, routes: list<CachedRoute>}>>}
 */
final class CachedMatcher implements RouteMatcher
{
    /** Version of the table layout; a table of another version is ignored. */
    private const int FORMAT = 1;

    /** @var CompiledTable|null */
    private ?array $table = null;

    private bool $loaded = false;

    public function __construct(
        private readonly RouteMatcher $inner,
        private readonly RouteCacheStore $store,
    ) {}

    #[\Override]
    public function match(string $method, string $uri): ?MatchResult
    {
        $table = $this->table();

        return $table === null ? $this->inner->match($method, $uri) : self::matchTable($table, strtoupper($method), $uri);
    }

    #[\Override]
    public function add(RouteEntry $route): void
    {
        $this->inner->add($route);
        $this->forget();
    }

    #[\Override]
    public function allowedMethods(string $uri): array
    {
        $table = $this->table();

        return $table === null ? $this->inner->allowedMethods($uri) : self::allowedFromTable($table, $uri);
    }

    /**
     * Compiles the inner matcher's routes and saves the table.
     *
     * @throws RouteCompilationException when a route or the matcher cannot be written out
     * @throws RouteCacheException       when the store refuses the table
     */
    public function warm(): void
    {
        if (!$this->inner instanceof CompositeRouteMatcher) {
            throw RouteCompilationException::uncacheableMatcher();
        }

        $this->store->save(self::compile($this->inner));
        $this->forget();
    }

    /**
     * Removes the stored table; matches go to the inner matcher again.
     *
     * @throws RouteCacheException when the store refuses the removal
     */
    public function clear(): void
    {
        $this->store->clear();
        $this->forget();
    }

    /** True when a table of this version, fresh for the inner routes, is stored. */
    public function isCached(): bool
    {
        return $this->table() !== null;
    }

    /**
     * @return CompiledTable|null
     */
    private function table(): ?array
    {
        if (!$this->loaded) {
            $this->loaded = true;
            $this->table = $this->freshTable($this->store->load());
        }

        return $this->table;
    }

    private function forget(): void
    {
        $this->loaded = false;
        $this->table = null;
    }

    /**
     * @param array<array-key, mixed>|null $data
     *
     * @return CompiledTable|null
     */
    private function freshTable(?array $data): ?array
    {
        if ($data === null || !self::hasTableLayout($data)) {
            return null;
        }

        if ($this->inner instanceof CompositeRouteMatcher && self::hasRoutes($this->inner) && self::checksumOrNull($this->inner) !== $data['checksum']) {
            return null;
        }

        /** @var CompiledTable $data */
        return $data;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function hasTableLayout(array $data): bool
    {
        return ($data['format'] ?? null) === self::FORMAT
            && is_string($data['checksum'] ?? null)
            && is_array($data['static'] ?? null)
            && is_array($data['dynamic'] ?? null);
    }

    private static function hasRoutes(CompositeRouteMatcher $composite): bool
    {
        return $composite->staticMatcher->exportMap() !== [] || $composite->regexMatcher->exportRoutes() !== [];
    }

    /**
     * @return CompiledTable
     */
    private static function compile(CompositeRouteMatcher $composite): array
    {
        $static = [];
        $dynamic = [];

        foreach ($composite->staticMatcher->exportMap() as $uri => $routes) {
            foreach ($routes as $method => $route) {
                $static[$uri][$method] = self::export($route);
            }
        }

        foreach ($composite->regexMatcher->exportCompiled() as $method => $chunks) {
            foreach ($chunks as $chunk) {
                $dynamic[$method][] = ['regex' => $chunk['regex'], 'routes' => array_map(self::export(...), $chunk['routes'])];
            }
        }

        return ['format' => self::FORMAT, 'checksum' => self::checksum($composite), 'static' => $static, 'dynamic' => $dynamic];
    }

    /**
     * A digest of every route as the table writes it, in matching order.
     *
     * @throws RouteCompilationException when a route cannot be written out
     */
    private static function checksum(CompositeRouteMatcher $composite): string
    {
        $routes = [];

        foreach ($composite->staticMatcher->exportMap() as $methods) {
            foreach ($methods as $method => $route) {
                $routes[] = [$method, self::export($route)];
            }
        }

        foreach ($composite->regexMatcher->exportRoutes() as $method => $entries) {
            $routes[] = [$method, array_map(self::export(...), $entries)];
        }

        return hash('sha256', serialize($routes));
    }

    /** The checksum, or null when a route cannot be written out, which no table can match. */
    private static function checksumOrNull(CompositeRouteMatcher $composite): ?string
    {
        try {
            return self::checksum($composite);
        } catch (RouteCompilationException) {
            return null;
        }
    }

    /**
     * @return CachedRoute
     *
     * @throws RouteCompilationException when the route cannot be written out
     */
    private static function export(RouteEntry $route): array
    {
        $handler = $route->handler;

        if ($handler instanceof Closure) {
            throw RouteCompilationException::uncacheableRoute($route->pattern, 'a Closure handler cannot be written out; use a string or a [class, method] pair');
        }

        if (!self::isExportable($route->metadata)) {
            throw RouteCompilationException::uncacheableRoute($route->pattern, 'its metadata holds a value other than a scalar, null or an array of those');
        }

        return [
            'methods' => $route->methods,
            'pattern' => $route->pattern,
            'handler' => $handler,
            'name' => $route->name,
            'middleware' => $route->middleware,
            'defaults' => $route->defaults,
            'metadata' => $route->metadata,
            'host' => $route->host,
            'scheme' => $route->scheme,
        ];
    }

    private static function isExportable(mixed $value): bool
    {
        return is_array($value)
            ? array_all($value, static fn(mixed $item): bool => self::isExportable($item))
            : $value === null || is_scalar($value);
    }

    /**
     * @param CompiledTable $table
     */
    private static function matchTable(array $table, string $method, string $uri): ?MatchResult
    {
        $static = $table['static'][$uri][$method] ?? null;

        if ($static !== null) {
            $route = self::restore($static);

            return new MatchResult($route, $route->defaults, $route->middleware);
        }

        foreach ($table['dynamic'][$method] ?? [] as $chunk) {
            $hit = RegexMatcher::locate($chunk['regex'], $uri);
            $data = $hit === null ? null : $chunk['routes'][$hit[0]] ?? null;

            if ($hit !== null && $data !== null) {
                $route = self::restore($data);

                return new MatchResult($route, $route->parametersFrom($hit[1]), $route->middleware);
            }
        }

        return null;
    }

    /**
     * @param CompiledTable $table
     *
     * @return list<string>
     */
    private static function allowedFromTable(array $table, string $uri): array
    {
        $allowed = array_keys($table['static'][$uri] ?? []);

        foreach ($table['dynamic'] as $method => $chunks) {
            if (array_any($chunks, static fn(array $chunk): bool => RegexMatcher::locate($chunk['regex'], $uri) !== null)) {
                $allowed[] = $method;
            }
        }

        return array_values(array_unique($allowed));
    }

    /**
     * @param CachedRoute $data
     */
    private static function restore(array $data): RouteEntry
    {
        return new RouteEntry(
            methods: $data['methods'],
            pattern: $data['pattern'],
            handler: $data['handler'],
            name: $data['name'],
            middleware: $data['middleware'],
            defaults: $data['defaults'],
            metadata: $data['metadata'],
            host: $data['host'],
            scheme: $data['scheme'],
        );
    }
}
