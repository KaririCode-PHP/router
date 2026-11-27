<?php

declare(strict_types=1);

namespace KaririCode\Router\Matcher;

use KaririCode\Router\Contract\RouteMatcher;
use KaririCode\Router\Core\MatchResult;
use KaririCode\Router\Core\RouteEntry;

/**
 * Compiled group-regex matcher for parameterized routes.
 *
 * ARFA 1.3 P2 (Reactive Flow Composition): second stage of the
 * composite matcher pipeline, invoked only when StaticMatcher misses.
 * Spec V4.0: final class. Compilation is lazy (first match triggers it).
 *
 * @see https://datatracker.ietf.org/doc/html/rfc3986#section-3.3 URI path matching
 *
 * Groups routes by HTTP method and compiles them into chunked combined
 * regular expressions (mark-based approach). This yields O(1) regex
 * compilation cost per match, amortized over the chunk size.
 *
 * Technique adapted from nikic/FastRoute's "mark-based" strategy but
 * extended with typed parameter extraction and chunk-size tuning.
 *
 * Chunk size of ~10 routes balances PCRE JIT efficiency against the
 * number of alternations.
 *
 * @see https://nikic.github.io/2014/02/18/Fast-request-routing-using-regular-expressions.html
 */
final class RegexMatcher implements RouteMatcher
{
    private const CHUNK_SIZE = 10;

    /** @var array<string, list<RouteEntry>> method → entries */
    private array $routesByMethod = [];

    /** @var array<string, list<array{regex:string,routeMap:array<int,RouteEntry>}>>|null */
    private ?array $compiled = null;

    public function match(string $method, string $uri): ?MatchResult
    {
        $this->ensureCompiled();

        $methodKey = strtoupper($method);
        $chunks = $this->compiled[$methodKey] ?? [];

        foreach ($chunks as $chunk) {
            if (!preg_match($chunk['regex'], $uri, $matches)) {
                continue;
            }

            // The mark tells us which route matched inside the combined regex
            $routeIndex = count($matches) - 1;

            // Walk backwards to find the last non-empty group (the MARK)
            while ($routeIndex > 0 && (!isset($matches[$routeIndex]) || $matches[$routeIndex] === '')) {
                --$routeIndex;
            }

            $route = $chunk['routeMap'][$routeIndex] ?? null;
            if ($route === null) {
                continue;
            }

            $params = [];
            foreach ($route->compiledPattern->parameterNames as $name) {
                if (isset($matches[$name]) && $matches[$name] !== '') {
                    $params[$name] = $matches[$name];
                }
            }

            // Apply defaults
            foreach ($route->defaults as $key => $default) {
                $params[$key] ??= $default;
            }

            return new MatchResult(
                route: $route,
                rawParameters: $params,
                middleware: $route->middleware,
            );
        }

        return null;
    }

    public function add(RouteEntry $route): void
    {
        if ($route->compiledPattern->isStatic) {
            return; // Static routes handled by StaticMatcher
        }

        foreach ($route->methods as $method) {
            $this->routesByMethod[$method][] = $route;
        }

        $this->compiled = null; // Invalidate compilation cache
    }

    public function allowedMethods(string $uri): array
    {
        $this->ensureCompiled();
        $allowed = [];

        foreach ($this->compiled as $method => $chunks) {
            foreach ($chunks as $chunk) {
                if (preg_match($chunk['regex'], $uri)) {
                    $allowed[] = $method;
                    break;
                }
            }
        }

        return $allowed;
    }

    // ── Compilation ──────────────────────────────────────────────────────

    private function ensureCompiled(): void
    {
        if ($this->compiled !== null) {
            return;
        }

        $this->compiled = [];

        foreach ($this->routesByMethod as $method => $routes) {
            $this->compiled[$method] = $this->compileChunks($routes);
        }
    }

    /**
     * @param list<RouteEntry> $routes
     * @return list<array{regex:string,routeMap:array<int,RouteEntry>}>
     */
    private function compileChunks(array $routes): array
    {
        $chunks = [];

        foreach (array_chunk($routes, self::CHUNK_SIZE) as $chunk) {
            $combined = '';
            $routeMap = [];
            $groupOffset = 1; // Group 0 is the full match

            foreach ($chunk as $route) {
                $pattern = $route->compiledPattern;

                // Strip anchors from individual regex for combining
                $inner = substr($pattern->regex, 2, -3); // Remove #^ and $#u

                $paramCount = count($pattern->parameterNames);

                // Append with a dummy group at the end as a mark
                $combined .= '|' . $inner . '()';

                // The mark group index
                $markIndex = $groupOffset + $paramCount;
                $routeMap[$markIndex] = $route;
                $groupOffset = $markIndex + 1;
            }

            $combined = '#^(?:' . substr($combined, 1) . ')$#u';

            $chunks[] = [
                'regex' => $combined,
                'routeMap' => $routeMap,
            ];
        }

        return $chunks;
    }

    /**
     * Export compiled data for caching.
     *
     * @return array<string, list<array{regex:string,routeMap:array<int,array{pattern:string,methods:list<string>,handler:mixed,name:?string,middleware:list<string>}>}>>
     */
    public function exportCompiled(): array
    {
        $this->ensureCompiled();
        return $this->compiled;
    }
}
