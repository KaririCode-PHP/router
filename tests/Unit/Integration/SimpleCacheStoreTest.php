<?php

declare(strict_types=1);

namespace KaririCode\Router\Tests\Unit\Integration;

use KaririCode\Router\Exception\RouteCacheException;
use KaririCode\Router\Exception\RouteCompilationException;
use KaririCode\Router\Exception\RouterCode;
use KaririCode\Router\Integration\SimpleCacheStore;
use KaririCode\Router\Tests\Fixture\InMemorySimpleCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SimpleCacheStore::class)]
final class SimpleCacheStoreTest extends TestCase
{
    #[Test]
    public function itKeepsTheTableUnderItsKey(): void
    {
        $cache = new InMemorySimpleCache();
        $store = new SimpleCacheStore($cache, 'app.routes', 3600);

        self::assertNull($store->load());

        $store->save(['format' => 1]);

        self::assertSame(['format' => 1], $cache->values['app.routes']);
        self::assertSame(3600, $cache->ttls['app.routes']);
        self::assertSame(['format' => 1], $store->load());

        $store->clear();

        self::assertSame([], $cache->values);
        self::assertNull($store->load());
    }

    #[Test]
    public function theDefaultKeyKeepsTheTableUntilCleared(): void
    {
        $cache = new InMemorySimpleCache();
        (new SimpleCacheStore($cache))->save(['format' => 1]);

        self::assertArrayHasKey('kariricode.router.table', $cache->values);
        self::assertNull($cache->ttls['kariricode.router.table']);
    }

    #[Test]
    public function aValueThatIsNotAnArrayIsAMiss(): void
    {
        $cache = new InMemorySimpleCache();
        $cache->values['kariricode.router.table'] = 'stale';

        self::assertNull((new SimpleCacheStore($cache))->load());
    }

    #[Test]
    public function aRefusedWriteOrDeleteFails(): void
    {
        $store = new SimpleCacheStore(new InMemorySimpleCache(accept: false));

        try {
            $store->save([]);
            self::fail('RouteCacheException expected');
        } catch (RouteCacheException $e) {
            self::assertSame(RouterCode::CacheWriteFailed, $e->errorCode);
            self::assertStringContainsString('"PSR-16 key kariricode.router.table"', $e->getMessage());
        }

        $this->expectException(RouteCacheException::class);
        $this->expectExceptionMessage('The route cache at "PSR-16 key kariricode.router.table" could not be written.');
        $store->clear();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'reserved character' => ['routes:v1'];
        yield 'space' => ['my routes'];
        yield 'too long' => [str_repeat('a', 65)];
    }

    #[Test]
    #[DataProvider('invalidKeys')]
    public function aKeyOutsideThePortableSetFailsAtConstruction(string $key): void
    {
        $this->expectException(RouteCompilationException::class);
        $this->expectExceptionCode(RouterCode::InvalidCacheKey->value);

        new SimpleCacheStore(new InMemorySimpleCache(), $key);
    }

    #[Test]
    public function aKeyOfSixtyFourPortableCharactersIsAccepted(): void
    {
        $cache = new InMemorySimpleCache();
        (new SimpleCacheStore($cache, str_repeat('a', 63) . '.'))->save([]);

        self::assertArrayHasKey(str_repeat('a', 63) . '.', $cache->values);
    }
}
