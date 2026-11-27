<?php

declare(strict_types=1);

namespace KaririCode\Router\Tests\Unit\Integration;

use KaririCode\Router\Core\RouteEntry;
use KaririCode\Router\Core\RouteRegistry;
use KaririCode\Router\Integration\CacheBridge;
use PHPUnit\Framework\TestCase;

final class CacheBridgeTest extends TestCase
{
    private function createFakeCache(): object
    {
        return new class {
            public array $store = [];

            public function get(string $key): mixed
            {
                return $this->store[$key] ?? null;
            }

            public function set(string $key, mixed $value, int $ttl = 0): void
            {
                $this->store[$key] = $value;
            }

            public function delete(string $key): void
            {
                unset($this->store[$key]);
            }
        };
    }

    public function testWarmOrRestoreReturnsFalseOnColdCache(): void
    {
        $cache = $this->createFakeCache();
        $bridge = new CacheBridge($cache, 'test.router');
        $registry = new RouteRegistry();
        $registry->addRoute(new RouteEntry(['GET'], '/test', [self::class, 'setUp']));

        self::assertFalse($bridge->warmOrRestore($registry));
    }

    public function testWarmOrRestoreReturnsTrueOnWarmCache(): void
    {
        $cache = $this->createFakeCache();
        $bridge = new CacheBridge($cache, 'test.router');
        $registry = new RouteRegistry();
        $registry->addRoute(new RouteEntry(['GET'], '/test', [self::class, 'setUp']));

        $bridge->warmOrRestore($registry);
        self::assertTrue($bridge->warmOrRestore($registry));
    }

    public function testInvalidateClearsCache(): void
    {
        $cache = $this->createFakeCache();
        $bridge = new CacheBridge($cache, 'test.router');
        $registry = new RouteRegistry();
        $registry->addRoute(new RouteEntry(['GET'], '/test', [self::class, 'setUp']));

        $bridge->warmOrRestore($registry);
        $bridge->invalidate();

        self::assertFalse($bridge->warmOrRestore($registry));
    }

    public function testCustomPrefix(): void
    {
        $cache = $this->createFakeCache();
        $bridge = new CacheBridge($cache, 'my.custom.prefix');
        $registry = new RouteRegistry();
        $registry->addRoute(new RouteEntry(['GET'], '/test', [self::class, 'setUp']));

        $bridge->warmOrRestore($registry);

        self::assertArrayHasKey('my.custom.prefix.compiled', $cache->store);
    }

    public function testNonCacheObjectDoesNotThrow(): void
    {
        $noop = new \stdClass();
        $bridge = new CacheBridge($noop);
        $registry = new RouteRegistry();

        self::assertFalse($bridge->warmOrRestore($registry));

        $bridge->invalidate();
        self::assertTrue(true);
    }
}
