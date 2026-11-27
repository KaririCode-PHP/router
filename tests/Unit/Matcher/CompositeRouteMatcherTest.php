<?php

declare(strict_types=1);

namespace KaririCode\Router\Tests\Unit\Matcher;

use KaririCode\Router\Core\RouteEntry;
use KaririCode\Router\Matcher\CompositeRouteMatcher;
use KaririCode\Router\Matcher\RegexMatcher;
use KaririCode\Router\Matcher\StaticMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompositeRouteMatcher::class)]
final class CompositeRouteMatcherTest extends TestCase
{
    #[Test]
    public function aStaticRouteBeatsAPatternRegisteredBeforeIt(): void
    {
        $matcher = new CompositeRouteMatcher();
        $matcher->add(new RouteEntry(['GET'], '/users/{id}', 'item'));
        $matcher->add(new RouteEntry(['GET'], '/users/new', 'create'));

        self::assertSame('create', $matcher->match('GET', '/users/new')?->handler());
        self::assertSame('item', $matcher->match('GET', '/users/7')?->handler());
        self::assertNull($matcher->match('GET', '/posts'));
    }

    #[Test]
    public function allowedMethodsJoinsBothTiersWithoutRepeats(): void
    {
        $matcher = new CompositeRouteMatcher();
        $matcher->add(new RouteEntry(['GET', 'POST'], '/users/new', 'create'));
        $matcher->add(new RouteEntry(['GET', 'DELETE'], '/users/{id}', 'item'));

        self::assertSame(['GET', 'POST', 'DELETE'], $matcher->allowedMethods('/users/new'));
        self::assertSame(['GET', 'DELETE'], $matcher->allowedMethods('/users/7'));
        self::assertSame([], $matcher->allowedMethods('/posts'));
    }

    #[Test]
    public function itHandsEachRouteToBothTiers(): void
    {
        $static = new StaticMatcher();
        $regex = new RegexMatcher();
        $matcher = new CompositeRouteMatcher($static, $regex);
        $matcher->add(new RouteEntry(['GET'], '/a', 'a'));
        $matcher->add(new RouteEntry(['GET'], '/b/{x}', 'b'));

        self::assertSame($static, $matcher->staticMatcher);
        self::assertSame($regex, $matcher->regexMatcher);
        self::assertArrayHasKey('/a', $static->exportMap());
        self::assertCount(1, $regex->exportRoutes()['GET']);
    }
}
