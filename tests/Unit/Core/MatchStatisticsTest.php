<?php

declare(strict_types=1);

namespace KaririCode\Router\Tests\Unit\Core;

use KaririCode\Router\Core\MatchResult;
use KaririCode\Router\Core\MatchStatistics;
use KaririCode\Router\Core\RouteEntry;
use KaririCode\Router\Exception\MethodNotAllowedException;
use KaririCode\Router\Exception\RouteNotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MatchStatistics::class)]
final class MatchStatisticsTest extends TestCase
{
    #[Test]
    public function itCountsHitsAndMissesAndAveragesDurations(): void
    {
        $statistics = new MatchStatistics();

        self::assertSame(0.0, $statistics->averageDurationNs());

        $statistics->routeMatched(new MatchResult(new RouteEntry(['GET'], '/x', 'h')), 100);
        $statistics->routeMatched(new MatchResult(new RouteEntry(['GET'], '/y', 'h')), 200);
        $statistics->routeMissed('GET', RouteNotFoundException::forRequest(), 300);
        $statistics->routeMissed('GET', RouteNotFoundException::forRequest(), 500);
        $statistics->routeMissed('DELETE', MethodNotAllowedException::allowing(['GET']), 400);

        self::assertSame(2, $statistics->matches);
        self::assertSame(2, $statistics->notFound);
        self::assertSame(1, $statistics->methodNotAllowed);
        self::assertSame(3, $statistics->misses());
        self::assertSame(1500, $statistics->totalDurationNs);
        self::assertSame(300.0, $statistics->averageDurationNs());
    }

    #[Test]
    public function resetZeroesEveryCounter(): void
    {
        $statistics = new MatchStatistics();
        $statistics->routeMatched(new MatchResult(new RouteEntry(['GET'], '/x', 'h')), 10);
        $statistics->routeMissed('GET', RouteNotFoundException::forRequest(), 10);
        $statistics->routeMissed('GET', MethodNotAllowedException::allowing(['POST']), 10);

        $statistics->reset();

        self::assertSame([0, 0, 0, 0], [$statistics->matches, $statistics->notFound, $statistics->methodNotAllowed, $statistics->totalDurationNs]);
        self::assertSame(0.0, $statistics->averageDurationNs());
    }
}
