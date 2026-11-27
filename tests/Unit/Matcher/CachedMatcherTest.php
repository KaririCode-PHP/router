<?php

declare(strict_types=1);

namespace KaririCode\Router\Tests\Unit\Matcher;

use KaririCode\Router\Core\MatchResult;
use KaririCode\Router\Core\RouteEntry;
use KaririCode\Router\Exception\RouteCompilationException;
use KaririCode\Router\Matcher\CachedMatcher;
use KaririCode\Router\Matcher\CompositeRouteMatcher;
use PHPUnit\Framework\TestCase;

final class CachedMatcherTest extends TestCase
{
    private string $cacheDir;
    private string $cachePath;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/cached_matcher_' . uniqid();
        mkdir($this->cacheDir, 0755, true);
        $this->cachePath = $this->cacheDir . '/routes.php';
    }

    protected function tearDown(): void
    {
        @unlink($this->cachePath);
        array_map('unlink', glob("{$this->cacheDir}/*.tmp") ?: []);
        @rmdir($this->cacheDir);
    }

    private function buildCachedMatcher(array $routes = []): CachedMatcher
    {
        $composite = new CompositeRouteMatcher();
        foreach ($routes as $route) {
            $composite->add($route);
        }
        return new CachedMatcher($composite, $this->cachePath);
    }

    // ── Cold Mode (no cache) ─────────────────────────────────────────────

    public function testColdModeMatchDelegatesToInner(): void
    {
        $cached = $this->buildCachedMatcher([
            new RouteEntry(['GET'], '/users', [self::class, 'setUp'], name: 'users.list'),
        ]);

        $result = $cached->match('GET', '/users');

        self::assertNotNull($result);
        self::assertSame('users.list', $result->routeName());
    }

    public function testColdModeReturnsNullOnMiss(): void
    {
        $cached = $this->buildCachedMatcher([
            new RouteEntry(['GET'], '/users', [self::class, 'setUp']),
        ]);

        self::assertNull($cached->match('GET', '/nonexistent'));
    }

    public function testColdModeAllowedMethods(): void
    {
        $cached = $this->buildCachedMatcher([
            new RouteEntry(['GET', 'POST'], '/users', [self::class, 'setUp']),
        ]);

        $allowed = $cached->allowedMethods('/users');
        sort($allowed);
        self::assertSame(['GET', 'POST'], $allowed);
    }

    // ── Cache Lifecycle ──────────────────────────────────────────────────

    public function testWarmCreatesCacheFile(): void
    {
        $cached = $this->buildCachedMatcher([
            new RouteEntry(['GET'], '/health', [self::class, 'setUp'], name: 'health'),
        ]);

        self::assertFalse($cached->isCached());

        $cached->warm();

        self::assertTrue($cached->isCached());
        self::assertFileExists($this->cachePath);
    }

    public function testCacheContainsValidPhpArray(): void
    {
        $cached = $this->buildCachedMatcher([
            new RouteEntry(['GET'], '/health', [self::class, 'setUp']),
        ]);
        $cached->warm();

        $data = include $this->cachePath;

        self::assertIsArray($data);
        self::assertArrayHasKey('static', $data);
        self::assertArrayHasKey('regex', $data);
        self::assertArrayHasKey('checksum', $data);
    }

    public function testClearRemovesCacheFile(): void
    {
        $cached = $this->buildCachedMatcher([
            new RouteEntry(['GET'], '/health', [self::class, 'setUp']),
        ]);
        $cached->warm();

        self::assertTrue($cached->isCached());
        $cached->clear();
        self::assertFalse($cached->isCached());
    }

    public function testClearWhenNotCachedDoesNotThrow(): void
    {
        $cached = $this->buildCachedMatcher();
        $cached->clear();
        self::assertFalse($cached->isCached());
    }

    // ── Warm Mode (cached matching) ──────────────────────────────────────

    public function testWarmModeMatchesStaticRoute(): void
    {
        $cached = $this->buildCachedMatcher([
            new RouteEntry(['GET'], '/health', [self::class, 'setUp'], name: 'health'),
            new RouteEntry(['POST'], '/users', [self::class, 'setUp'], name: 'users.create'),
        ]);
        $cached->warm();

        // Fresh instance loads from cache only
        $fresh = new CachedMatcher(new CompositeRouteMatcher(), $this->cachePath);

        $result = $fresh->match('GET', '/health');
        self::assertNotNull($result);
        self::assertSame('health', $result->routeName());
    }

    public function testWarmModeMatchesParameterizedRoute(): void
    {
        $cached = $this->buildCachedMatcher([
            new RouteEntry(['GET'], '/users/{id:int}', [self::class, 'setUp'], name: 'users.show'),
        ]);
        $cached->warm();

        $fresh = new CachedMatcher(new CompositeRouteMatcher(), $this->cachePath);

        $result = $fresh->match('GET', '/users/42');
        self::assertNotNull($result);
        self::assertSame('users.show', $result->routeName());
        self::assertSame('42', $result->parameters->get('id'));
    }

    public function testWarmModeReturnsNullOnMiss(): void
    {
        $cached = $this->buildCachedMatcher([
            new RouteEntry(['GET'], '/health', [self::class, 'setUp']),
        ]);
        $cached->warm();

        $fresh = new CachedMatcher(new CompositeRouteMatcher(), $this->cachePath);
        self::assertNull($fresh->match('GET', '/nonexistent'));
    }

    public function testWarmModeAllowedMethods(): void
    {
        $cached = $this->buildCachedMatcher([
            new RouteEntry(['GET'], '/users', [self::class, 'setUp']),
            new RouteEntry(['POST'], '/users', [self::class, 'setUp']),
            new RouteEntry(['GET'], '/users/{id:int}', [self::class, 'setUp']),
            new RouteEntry(['DELETE'], '/users/{id:int}', [self::class, 'setUp']),
        ]);
        $cached->warm();

        $fresh = new CachedMatcher(new CompositeRouteMatcher(), $this->cachePath);

        $staticAllowed = $fresh->allowedMethods('/users');
        sort($staticAllowed);
        self::assertSame(['GET', 'POST'], $staticAllowed);

        $regexAllowed = $fresh->allowedMethods('/users/1');
        sort($regexAllowed);
        self::assertSame(['DELETE', 'GET'], $regexAllowed);
    }

    public function testWarmModePreservesMiddleware(): void
    {
        $cached = $this->buildCachedMatcher([
            new RouteEntry(['GET'], '/admin', [self::class, 'setUp'], middleware: ['AuthMiddleware', 'RateLimitMiddleware']),
        ]);
        $cached->warm();

        $fresh = new CachedMatcher(new CompositeRouteMatcher(), $this->cachePath);
        $result = $fresh->match('GET', '/admin');

        self::assertSame(['AuthMiddleware', 'RateLimitMiddleware'], $result->middleware);
    }

    public function testWarmModePreservesDefaults(): void
    {
        $cached = $this->buildCachedMatcher([
            new RouteEntry(['GET'], '/posts/{page:int}', [self::class, 'setUp'], defaults: ['page' => '1']),
        ]);
        $cached->warm();

        $fresh = new CachedMatcher(new CompositeRouteMatcher(), $this->cachePath);
        $result = $fresh->match('GET', '/posts/5');

        self::assertSame('5', $result->parameters->get('page'));
    }

    // ── Error Handling ───────────────────────────────────────────────────

    public function testWarmThrowsForClosureHandler(): void
    {
        $cached = $this->buildCachedMatcher([
            new RouteEntry(['GET'], '/lambda', fn () => 'ok'),
        ]);

        $this->expectException(RouteCompilationException::class);
        $this->expectExceptionMessage('Closure handlers cannot be cached');
        $cached->warm();
    }

    public function testWarmCreatesNestedDirectory(): void
    {
        $deepPath = $this->cacheDir . '/nested/deep/routes.php';
        $composite = new CompositeRouteMatcher();
        $composite->add(new RouteEntry(['GET'], '/test', [self::class, 'setUp']));
        $cached = new CachedMatcher($composite, $deepPath);

        $cached->warm();

        self::assertFileExists($deepPath);

        @unlink($deepPath);
        @rmdir($this->cacheDir . '/nested/deep');
        @rmdir($this->cacheDir . '/nested');
    }

    public function testChecksumDeterministic(): void
    {
        $routes = [
            new RouteEntry(['GET'], '/a', [self::class, 'setUp']),
            new RouteEntry(['POST'], '/b', [self::class, 'setUp']),
        ];

        $c1 = $this->buildCachedMatcher($routes);
        $c1->warm();
        $d1 = include $this->cachePath;

        @unlink($this->cachePath);

        $c2 = $this->buildCachedMatcher($routes);
        $c2->warm();
        $d2 = include $this->cachePath;

        self::assertSame($d1['checksum'], $d2['checksum']);
    }
}
