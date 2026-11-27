<?php

declare(strict_types=1);

namespace KaririCode\Router\Tests\Unit\Matcher;

use KaririCode\Router\Core\RouteEntry;
use KaririCode\Router\Exception\RouteCompilationException;
use KaririCode\Router\Exception\RouterCode;
use KaririCode\Router\Matcher\CachedMatcher;
use KaririCode\Router\Matcher\CompositeRouteMatcher;
use KaririCode\Router\Matcher\StaticMatcher;
use KaririCode\Router\Tests\Fixture\InMemoryRouteCacheStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CachedMatcher::class)]
final class CachedMatcherTest extends TestCase
{
    #[Test]
    public function withoutATableEveryCallGoesToTheInnerMatcher(): void
    {
        $store = new InMemoryRouteCacheStore();
        $matcher = new CachedMatcher(self::composite(), $store);

        self::assertFalse($matcher->isCached());
        self::assertSame('show', $matcher->match('GET', '/users/7')?->handler());
        self::assertSame(['GET', 'HEAD', 'DELETE'], $matcher->allowedMethods('/users/7'));
        self::assertSame(1, $store->loads, 'the store is read once');
    }

    #[Test]
    public function warmWritesATableThatServesFreshInstances(): void
    {
        $store = new InMemoryRouteCacheStore();
        (new CachedMatcher(self::composite(), $store))->warm();

        self::assertSame(1, $store->saves);
        self::assertSame(1, $store->stored['format'] ?? null);

        $cached = new CachedMatcher(new CompositeRouteMatcher(), $store);

        self::assertTrue($cached->isCached());
        $static = $cached->match('head', '/health');
        self::assertSame('HealthCheck', $static?->handler());
        self::assertSame(['format' => 'json'], $static->parameters->all());
        self::assertSame(['GET', 'HEAD'], $static->route->methods);

        $dynamic = $cached->match('GET', '/users/7');
        self::assertSame('show', $dynamic?->handler());
        self::assertSame('users.show', $dynamic->routeName());
        self::assertSame(['tab' => 'profile', 'id' => '7'], $dynamic->parameters->all());
        self::assertSame(['auth'], $dynamic->middleware);
        self::assertSame(['limit' => 5, 'tags' => ['a', null, 1.5, true]], $dynamic->route->metadata);
        self::assertSame('api.test', $dynamic->route->host);
        self::assertSame('https', $dynamic->route->scheme);

        $post = $cached->match('POST', '/posts/a/b');
        self::assertNotNull($post);
        self::assertSame(['POST'], $post->route->methods);
        self::assertSame(['a' => 'a', 'b' => 'b'], $post->parameters->all());
        self::assertNull($cached->match('GET', '/posts/a/b'));
        self::assertNull($cached->match('GET', '/nope'));
        self::assertSame(['GET', 'HEAD'], $cached->allowedMethods('/health'));
        self::assertSame(['GET', 'HEAD', 'DELETE'], $cached->allowedMethods('/users/7'));
        self::assertSame(['POST'], $cached->allowedMethods('/posts/a/b'));
        self::assertSame(['GET', 'HEAD', 'DELETE'], $cached->allowedMethods('/users/0'), 'a path both static and dynamic, without repeats');
        self::assertSame([], $cached->allowedMethods('/nope'));
    }

    #[Test]
    public function warmForgetsAnEarlierMiss(): void
    {
        $matcher = new CachedMatcher(self::composite(), new InMemoryRouteCacheStore());
        self::assertFalse($matcher->isCached());

        $matcher->warm();

        self::assertTrue($matcher->isCached());
    }

    #[Test]
    public function aChangedPatternRouteMakesTheTableStale(): void
    {
        $store = new InMemoryRouteCacheStore();
        (new CachedMatcher(self::composite(), $store))->warm();

        $changed = new CompositeRouteMatcher();
        $changed->add(new RouteEntry(['GET', 'HEAD'], '/health', 'HealthCheck', defaults: ['format' => 'json']));
        $changed->add(new RouteEntry(['POST'], '/posts/{a}/{b}', 'other'));

        self::assertFalse((new CachedMatcher($changed, $store))->isCached());
    }

    #[Test]
    public function aTableIsUsedWhenTheInnerRoutesAreTheSame(): void
    {
        $store = new InMemoryRouteCacheStore();
        (new CachedMatcher(self::composite(), $store))->warm();

        $cached = new CachedMatcher(self::composite(), $store);

        self::assertTrue($cached->isCached());
    }

    #[Test]
    public function aStaleTableIsIgnored(): void
    {
        $store = new InMemoryRouteCacheStore();
        (new CachedMatcher(self::composite(), $store))->warm();

        $changed = self::composite();
        $changed->add(new RouteEntry(['GET'], '/added', 'Added'));
        $cached = new CachedMatcher($changed, $store);

        self::assertFalse($cached->isCached());
        self::assertSame('Added', $cached->match('GET', '/added')?->handler());
    }

    #[Test]
    public function aChangedHandlerMakesTheTableStale(): void
    {
        $store = new InMemoryRouteCacheStore();
        (new CachedMatcher(self::composite(), $store))->warm();

        $changed = new CompositeRouteMatcher();
        $changed->add(new RouteEntry(['GET', 'HEAD'], '/health', 'OtherCheck', defaults: ['format' => 'json']));

        self::assertFalse((new CachedMatcher($changed, $store))->isCached());
    }

    #[Test]
    public function aTableIsIgnoredWhenTheInnerRoutesCannotBeWrittenOut(): void
    {
        $store = new InMemoryRouteCacheStore();
        (new CachedMatcher(self::composite(), $store))->warm();

        $closures = new CompositeRouteMatcher();
        $closures->add(new RouteEntry(['GET'], '/x', static fn(): null => null));
        $dynamic = new CompositeRouteMatcher();
        $dynamic->add(new RouteEntry(['GET'], '/x/{id}', static fn(): null => null));

        self::assertFalse((new CachedMatcher($closures, $store))->isCached());
        self::assertFalse((new CachedMatcher($dynamic, $store))->isCached());
    }

    #[Test]
    public function addingARouteRechecksTheTable(): void
    {
        $store = new InMemoryRouteCacheStore();
        $matcher = new CachedMatcher(self::composite(), $store);
        $matcher->warm();
        self::assertTrue($matcher->isCached());

        $matcher->add(new RouteEntry(['GET'], '/late', 'Late'));

        self::assertFalse($matcher->isCached());
        self::assertSame('Late', $matcher->match('GET', '/late')?->handler());
    }

    #[Test]
    public function clearRemovesTheTable(): void
    {
        $store = new InMemoryRouteCacheStore();
        $matcher = new CachedMatcher(new CompositeRouteMatcher(), $store);
        (new CachedMatcher(self::composite(), $store))->warm();
        self::assertTrue($matcher->isCached());

        $matcher->clear();

        self::assertSame(1, $store->clears);
        self::assertNull($store->stored);
        self::assertFalse($matcher->isCached());
    }

    #[Test]
    public function aTableOfAnotherLayoutIsIgnored(): void
    {
        $store = new InMemoryRouteCacheStore();
        (new CachedMatcher(self::composite(), $store))->warm();
        $valid = $store->stored ?? [];

        foreach ([['format' => 2], ['format' => '1'], ['checksum' => 7], ['static' => 'x'], ['dynamic' => null]] as $change) {
            $store->stored = [...$valid, ...$change];
            self::assertFalse((new CachedMatcher(new CompositeRouteMatcher(), $store))->isCached(), (string) json_encode($change));
        }

        $store->stored = $valid;
        self::assertTrue((new CachedMatcher(new CompositeRouteMatcher(), $store))->isCached());
    }

    #[Test]
    public function warmNeedsACompositeMatcher(): void
    {
        $this->expectException(RouteCompilationException::class);
        $this->expectExceptionCode(RouterCode::UncacheableMatcher->value);

        (new CachedMatcher(new StaticMatcher(), new InMemoryRouteCacheStore()))->warm();
    }

    #[Test]
    public function warmRejectsAClosureHandler(): void
    {
        $composite = new CompositeRouteMatcher();
        $composite->add(new RouteEntry(['GET'], '/x/{id}', static fn(): null => null));

        $this->expectException(RouteCompilationException::class);
        $this->expectExceptionCode(RouterCode::UncacheableRoute->value);
        $this->expectExceptionMessage('a Closure handler cannot be written out');

        (new CachedMatcher($composite, new InMemoryRouteCacheStore()))->warm();
    }

    #[Test]
    public function warmRejectsMetadataItCannotWriteOut(): void
    {
        $composite = new CompositeRouteMatcher();
        $composite->add(new RouteEntry(['GET'], '/x', 'h', metadata: ['nested' => ['clock' => new \DateTimeImmutable()]]));
        $store = new InMemoryRouteCacheStore();

        try {
            (new CachedMatcher($composite, $store))->warm();
            self::fail('RouteCompilationException expected');
        } catch (RouteCompilationException $e) {
            self::assertSame(RouterCode::UncacheableRoute, $e->errorCode);
            self::assertStringContainsString('its metadata holds', $e->getMessage());
            self::assertSame(0, $store->saves);
        }
    }

    private static function composite(): CompositeRouteMatcher
    {
        $composite = new CompositeRouteMatcher();
        $composite->add(new RouteEntry(['GET', 'HEAD'], '/health', 'HealthCheck', defaults: ['format' => 'json']));
        $composite->add(new RouteEntry(
            methods: ['GET', 'HEAD'],
            pattern: '/users/{id:int}',
            handler: 'show',
            name: 'users.show',
            middleware: ['auth'],
            defaults: ['tab' => 'profile'],
            metadata: ['limit' => 5, 'tags' => ['a', null, 1.5, true]],
            host: 'api.test',
            scheme: 'https',
        ));
        $composite->add(new RouteEntry(['DELETE'], '/users/{id:int}', ['UserController', 'delete']));
        $composite->add(new RouteEntry(['GET', 'HEAD'], '/users/0', 'zero'));
        $composite->add(new RouteEntry(['POST'], '/posts/{a}/{b}', 'post'));

        return $composite;
    }
}
