<?php

declare(strict_types=1);

namespace KaririCode\Router\Matcher;

use KaririCode\Router\Contract\RouteMatcher;
use KaririCode\Router\Core\MatchResult;
use KaririCode\Router\Core\RouteEntry;
use KaririCode\Router\Exception\RouteCompilationException;

/**
 * OPcache-friendly cached matcher with dual-mode dispatching.
 *
 * ARFA 1.3 P3 (Adaptive Optimization): zero regex compilation and
 * zero route registration after warm-up — the compiled route table
 * is stored as a PHP array file that OPcache serves from shared memory.
 * Spec V4.0: final class.
 *
 * Dual-mode operation:
 *
 *   Cold (no cache file): delegates to the inner matcher for every
 *   request. Call {@see warm()} from a CLI command (e.g. route:cache)
 *   to compile the cache.
 *
 *   Warm (cache file exists): loads the PHP array via {@see include},
 *   hitting OPcache directly. Static routes are resolved in O(1) via
 *   hash lookup. Parameterized routes use pre-compiled chunked regex
 *   — zero regex compilation, zero file I/O after the first include.
 *
 * Technique adapted from nikic/FastRoute's cached dispatcher and
 * Symfony's CompiledUrlMatcher, extended with typed parameter
 * extraction and protocol-agnostic method keys (ARFA 1.3 P4).
 *
 * Handlers must be serializable (string or array). Closure-based
 * routes throw RouteCompilationException on warm(), matching
 * Laravel's route:cache behavior.
 *
 * ADR-003: OPcache file cache chosen over serialized blobs for lowest
 * deserialization overhead. Atomic write via temp-file + rename
 * prevents partial reads under concurrent deploys.
 *
 * @see https://www.php-fig.org/psr/psr-6/ PSR-6 Caching Interface
 * @see https://nikic.github.io/2014/02/18/Fast-request-routing-using-regular-expressions.html
 */
final class CachedMatcher implements RouteMatcher
{
    /**
     * Cached dispatch data loaded from the PHP array file.
     *
     * @var array{static:array,regex:array,checksum:string}|null
     */
    private ?array $cache = null;

    private bool $cacheLoaded = false;

    public function __construct(
        private readonly RouteMatcher $inner,
        private readonly string $cachePath,
    ) {
    }

    // ── RouteMatcher Contract ────────────────────────────────────────────

    public function match(string $method, string $uri): ?MatchResult
    {
        if ($this->loadCacheIfAvailable()) {
            return $this->matchFromCache($method, $uri);
        }

        return $this->inner->match($method, $uri);
    }

    public function add(RouteEntry $route): void
    {
        $this->inner->add($route);
    }

    public function allowedMethods(string $uri): array
    {
        if ($this->loadCacheIfAvailable()) {
            return $this->allowedMethodsFromCache($uri);
        }

        return $this->inner->allowedMethods($uri);
    }

    // ── Cache Lifecycle ──────────────────────────────────────────────────

    /**
     * Compile and write the cache file from the current route definitions.
     *
     * Requires the inner matcher to be a {@see CompositeRouteMatcher}
     * so compiled data can be extracted. Typically called from a CLI
     * command (e.g. route:cache).
     *
     * @throws RouteCompilationException If the inner matcher is not a
     *         CompositeRouteMatcher, if routes contain Closure handlers,
     *         or if the file cannot be written.
     */
    public function warm(): void
    {
        if (!$this->inner instanceof CompositeRouteMatcher) {
            throw RouteCompilationException::invalidPattern(
                'CachedMatcher',
                'Inner matcher must be CompositeRouteMatcher for cache export',
            );
        }

        $data = $this->exportFromComposite($this->inner);

        $dir = dirname($this->cachePath);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new RouteCompilationException(
                "Cannot create cache directory: {$dir}",
            );
        }

        // Atomic write: temp file + rename prevents partial reads
        $tempPath = $this->cachePath . '.' . bin2hex(random_bytes(4)) . '.tmp';

        try {
            $content = "<?php\n\n// Auto-generated route cache — do not edit.\n"
                . '// Generated: ' . date('Y-m-d H:i:s T') . "\n\n"
                . 'return ' . var_export($data, true) . ";\n";

            $bytes = file_put_contents($tempPath, $content, LOCK_EX);

            if ($bytes === false) {
                throw new RouteCompilationException(
                    "Failed to write route cache: {$this->cachePath}",
                );
            }

            rename($tempPath, $this->cachePath);

            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($this->cachePath, true);
            }

            $this->cache = null;
            $this->cacheLoaded = false;
        } catch (RouteCompilationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            @unlink($tempPath);
            throw new RouteCompilationException(
                "Route cache compilation failed: {$e->getMessage()}",
                $e,
            );
        }
    }

    public function clear(): void
    {
        if (is_file($this->cachePath)) {
            unlink($this->cachePath);

            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($this->cachePath, true);
            }
        }

        $this->cache = null;
        $this->cacheLoaded = false;
    }

    public function isCached(): bool
    {
        return is_file($this->cachePath);
    }

    // ── Cache Loading ────────────────────────────────────────────────────

    private function loadCacheIfAvailable(): bool
    {
        if ($this->cacheLoaded) {
            return $this->cache !== null;
        }

        $this->cacheLoaded = true;

        if (!is_file($this->cachePath)) {
            return false;
        }

        $data = include $this->cachePath;

        if (!is_array($data) || !isset($data['static'], $data['regex'], $data['checksum'])) {
            return false;
        }

        $this->cache = $data;
        return true;
    }

    // ── Cached Matching ──────────────────────────────────────────────────

    private function matchFromCache(string $method, string $uri): ?MatchResult
    {
        $methodKey = strtoupper($method);

        // Fast path: O(1) static route lookup
        $staticEntry = $this->cache['static'][$uri][$methodKey] ?? null;
        if ($staticEntry !== null) {
            return $this->buildResultFromCachedEntry($staticEntry, []);
        }

        // Slow path: chunked regex matching
        $chunks = $this->cache['regex'][$methodKey] ?? [];

        foreach ($chunks as $chunk) {
            if (!preg_match($chunk['regex'], $uri, $matches)) {
                continue;
            }

            // Walk backwards to find the mark group (last non-empty capture)
            $routeIndex = count($matches) - 1;
            while ($routeIndex > 0 && (!isset($matches[$routeIndex]) || $matches[$routeIndex] === '')) {
                --$routeIndex;
            }

            $routeData = $chunk['routeMap'][$routeIndex] ?? null;
            if ($routeData === null) {
                continue;
            }

            $params = [];
            foreach ($routeData['params'] as $name) {
                if (isset($matches[$name]) && $matches[$name] !== '') {
                    $params[$name] = $matches[$name];
                }
            }

            foreach ($routeData['defaults'] as $key => $default) {
                $params[$key] ??= $default;
            }

            return $this->buildResultFromCachedEntry($routeData, $params);
        }

        return null;
    }

    private function allowedMethodsFromCache(string $uri): array
    {
        $allowed = [];

        if (isset($this->cache['static'][$uri])) {
            $allowed = array_keys($this->cache['static'][$uri]);
        }

        foreach ($this->cache['regex'] as $method => $chunks) {
            if (in_array($method, $allowed, true)) {
                continue;
            }
            foreach ($chunks as $chunk) {
                if (preg_match($chunk['regex'], $uri)) {
                    $allowed[] = $method;
                    break;
                }
            }
        }

        return $allowed;
    }

    /**
     * Reconstruct RouteEntry from cached data and build MatchResult.
     *
     * Individual pattern recompilation is O(1) and only happens once
     * per matched request — the expensive chunked regex compilation
     * (combining N routes into a single alternation) was cached.
     *
     * @param array<string, string> $params
     */
    private function buildResultFromCachedEntry(array $routeData, array $params): MatchResult
    {
        $route = new RouteEntry(
            methods: $routeData['methods'],
            pattern: $routeData['pattern'],
            handler: $routeData['handler'],
            name: $routeData['name'],
            middleware: $routeData['middleware'],
            defaults: $routeData['defaults'],
            metadata: $routeData['metadata'] ?? [],
            host: $routeData['host'],
            scheme: $routeData['scheme'],
        );

        return new MatchResult(
            route: $route,
            rawParameters: $params,
            middleware: $route->middleware,
        );
    }

    // ── Cache Export ─────────────────────────────────────────────────────

    private function exportFromComposite(CompositeRouteMatcher $composite): array
    {
        $staticMap = $composite->getStaticMatcher()->exportMap();
        $regexCompiled = $composite->getRegexMatcher()->exportCompiled();

        $staticExport = [];
        $checkParts = [];

        foreach ($staticMap as $uri => $methodEntries) {
            foreach ($methodEntries as $method => $entry) {
                $this->assertSerializableHandler($entry);
                $staticExport[$uri][$method] = $this->routeToArray($entry);
                $checkParts[] = "{$method}:{$uri}";
            }
        }

        $regexExport = [];
        foreach ($regexCompiled as $method => $chunks) {
            foreach ($chunks as $chunk) {
                $exportedMap = [];
                foreach ($chunk['routeMap'] as $index => $entry) {
                    $this->assertSerializableHandler($entry);
                    $exportedMap[$index] = $this->routeToArray($entry);
                    $checkParts[] = "{$method}:{$entry->pattern}";
                }
                $regexExport[$method][] = [
                    'regex' => $chunk['regex'],
                    'routeMap' => $exportedMap,
                ];
            }
        }

        sort($checkParts);

        return [
            'static' => $staticExport,
            'regex' => $regexExport,
            'checksum' => hash('sha256', implode('|', $checkParts)),
        ];
    }

    private function routeToArray(RouteEntry $entry): array
    {
        return [
            'handler' => $entry->handler,
            'name' => $entry->name,
            'methods' => $entry->methods,
            'pattern' => $entry->pattern,
            'middleware' => $entry->middleware,
            'defaults' => $entry->defaults,
            'metadata' => $entry->metadata,
            'host' => $entry->host,
            'scheme' => $entry->scheme,
            'params' => $entry->compiledPattern->parameterNames,
        ];
    }

    /**
     * @throws RouteCompilationException If handler is a Closure.
     */
    private function assertSerializableHandler(RouteEntry $entry): void
    {
        if ($entry->handler instanceof \Closure) {
            throw RouteCompilationException::invalidPattern(
                $entry->pattern,
                'Closure handlers cannot be cached. Use [Controller::class, "method"] syntax.',
            );
        }
    }
}
