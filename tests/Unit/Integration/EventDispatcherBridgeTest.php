<?php

declare(strict_types=1);

namespace KaririCode\Router\Tests\Unit\Integration;

use KaririCode\Router\Core\MatchResult;
use KaririCode\Router\Core\RouteEntry;
use KaririCode\Router\Event\RouteMatched;
use KaririCode\Router\Event\RouteMissed;
use KaririCode\Router\Exception\MethodNotAllowedException;
use KaririCode\Router\Integration\EventDispatcherBridge;
use KaririCode\Router\Tests\Fixture\RecordingDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EventDispatcherBridge::class)]
#[CoversClass(RouteMatched::class)]
#[CoversClass(RouteMissed::class)]
final class EventDispatcherBridgeTest extends TestCase
{
    #[Test]
    public function eachNotificationBecomesATypedEvent(): void
    {
        $dispatcher = new RecordingDispatcher();
        $bridge = new EventDispatcherBridge($dispatcher);
        $result = new MatchResult(new RouteEntry(['GET'], '/x', 'h'));
        $failure = MethodNotAllowedException::allowing(['GET']);

        $bridge->routeMatched($result, 120);
        $bridge->routeMissed('DELETE', $failure, 80);

        self::assertCount(2, $dispatcher->events);
        [$matched, $missed] = $dispatcher->events;
        self::assertInstanceOf(RouteMatched::class, $matched);
        self::assertSame($result, $matched->result);
        self::assertSame(120, $matched->durationNs);
        self::assertInstanceOf(RouteMissed::class, $missed);
        self::assertSame('DELETE', $missed->method);
        self::assertSame($failure, $missed->failure);
        self::assertSame(80, $missed->durationNs);
    }
}
