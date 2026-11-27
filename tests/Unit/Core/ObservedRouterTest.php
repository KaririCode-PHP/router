<?php

declare(strict_types=1);

namespace KaririCode\Router\Tests\Unit\Core;

use KaririCode\Router\Core\ObservedRouter;
use KaririCode\Router\Core\RouteEntry;
use KaririCode\Router\Core\RouteRegistry;
use KaririCode\Router\Exception\MethodNotAllowedException;
use KaririCode\Router\Exception\RouteNotFoundException;
use KaririCode\Router\Tests\Fixture\RecordingObserver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ObservedRouter::class)]
final class ObservedRouterTest extends TestCase
{
    #[Test]
    public function aMatchIsReportedAndReturned(): void
    {
        $observer = new RecordingObserver();
        $router = new ObservedRouter((new RouteRegistry())->get('/health', 'HealthCheck'), $observer);

        $result = $router->match('GET', '/health');

        self::assertSame('HealthCheck', $result->handler());
        self::assertCount(1, $observer->matched);
        self::assertSame($result, $observer->matched[0][0]);
        self::assertGreaterThanOrEqual(0, $observer->matched[0][1]);
        self::assertLessThan(10_000_000_000, $observer->matched[0][1], 'a duration, not a timestamp');
        self::assertSame([], $observer->missed);
    }

    #[Test]
    public function aMissIsReportedAndRethrown(): void
    {
        $observer = new RecordingObserver();
        $router = new ObservedRouter((new RouteRegistry())->get('/health', 'HealthCheck'), $observer);

        try {
            $router->match('DELETE', '/health');
            self::fail('MethodNotAllowedException expected');
        } catch (MethodNotAllowedException $e) {
            self::assertSame('DELETE', $observer->missed[0][0]);
            self::assertSame($e, $observer->missed[0][1]);
            self::assertGreaterThanOrEqual(0, $observer->missed[0][2]);
            self::assertLessThan(10_000_000_000, $observer->missed[0][2]);
        }

        try {
            $router->match('GET', '/nope');
            self::fail('RouteNotFoundException expected');
        } catch (RouteNotFoundException $e) {
            self::assertSame($e, $observer->missed[1][1]);
        }

        self::assertSame([], $observer->matched);
    }

    #[Test]
    public function registrationAndListingGoToTheInnerRouter(): void
    {
        $registry = new RouteRegistry();
        $router = new ObservedRouter($registry, new RecordingObserver());
        $route = new RouteEntry(['GET'], '/x', 'h');

        $router->addRoute($route);

        self::assertSame([$route], $registry->routes());
        self::assertSame([$route], $router->routes());
    }
}
