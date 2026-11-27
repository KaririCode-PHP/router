<?php

declare(strict_types=1);

namespace KaririCode\Router\Tests\Unit\Matcher;

use KaririCode\Router\Core\RouteEntry;
use KaririCode\Router\Matcher\StaticMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(StaticMatcher::class)]
final class StaticMatcherTest extends TestCase
{
    #[Test]
    public function itMatchesLiteralPathsPerMethod(): void
    {
        $matcher = new StaticMatcher();
        $route = new RouteEntry(['GET', 'HEAD'], '/health', 'HealthCheck', middleware: ['m'], defaults: ['format' => 'json']);
        $matcher->add($route);

        $result = $matcher->match('head', '/health');

        self::assertSame($route, $result?->route);
        self::assertSame(['format' => 'json'], $result->parameters->all());
        self::assertSame(['m'], $result->middleware);
        self::assertNull($matcher->match('POST', '/health'));
        self::assertNull($matcher->match('GET', '/health/'));
    }

    #[Test]
    public function patternsWithPlaceholdersAreLeftToRegexMatcher(): void
    {
        $matcher = new StaticMatcher();
        $matcher->add(new RouteEntry(['GET'], '/users/{id}', 'h'));

        self::assertNull($matcher->match('GET', '/users/{id}'));
        self::assertSame([], $matcher->exportMap());
    }

    #[Test]
    public function theFirstRouteForAMethodAndPathWins(): void
    {
        $matcher = new StaticMatcher();
        $first = new RouteEntry(['GET'], '/x', 'first');
        $matcher->add($first);
        $matcher->add(new RouteEntry(['GET', 'POST'], '/x', 'second'));

        self::assertSame('first', $matcher->match('GET', '/x')?->handler());
        self::assertSame('second', $matcher->match('POST', '/x')?->handler());
        self::assertSame(['GET', 'POST'], $matcher->allowedMethods('/x'));
        self::assertSame([], $matcher->allowedMethods('/y'));
        self::assertSame($first, $matcher->exportMap()['/x']['GET']);
        self::assertSame('second', $matcher->exportMap()['/x']['POST']->handler);
    }
}
