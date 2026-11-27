<?php

declare(strict_types=1);

namespace KaririCode\Router\Tests\Unit\Integration;

use KaririCode\Router\Core\MatchResult;
use KaririCode\Router\Core\RouteEntry;
use KaririCode\Router\Integration\EventDispatcherBridge;
use PHPUnit\Framework\TestCase;

final class EventDispatcherBridgeTest extends TestCase
{
    private function createDispatcher(): object
    {
        return new class {
            /** @var list<\stdClass> */
            public array $dispatched = [];

            public function dispatch(object $event): object
            {
                $this->dispatched[] = $event;
                return $event;
            }
        };
    }

    private function createResult(string $name = 'test.route'): MatchResult
    {
        return new MatchResult(
            new RouteEntry(['GET'], '/test/{id:int}', [self::class, 'setUp'], name: $name),
            ['id' => '42'],
        );
    }

    public function testRouteMatched(): void
    {
        $dispatcher = $this->createDispatcher();
        $bridge = new EventDispatcherBridge($dispatcher);

        $bridge->routeMatched($this->createResult(), 1.234);

        self::assertCount(1, $dispatcher->dispatched);

        $event = $dispatcher->dispatched[0];
        self::assertSame('router.matched', $event->name);
        self::assertSame('test.route', $event->payload['route']);
        self::assertSame('GET', $event->payload['method']);
        self::assertSame(1.234, $event->payload['duration_ms']);
    }

    public function testRouteNotFound(): void
    {
        $dispatcher = $this->createDispatcher();
        $bridge = new EventDispatcherBridge($dispatcher);

        $bridge->routeNotFound('POST', '/nonexistent', 0.5);

        $event = $dispatcher->dispatched[0];
        self::assertSame('router.not_found', $event->name);
        self::assertSame('POST', $event->payload['method']);
        self::assertSame('/nonexistent', $event->payload['uri']);
    }

    public function testMethodNotAllowed(): void
    {
        $dispatcher = $this->createDispatcher();
        $bridge = new EventDispatcherBridge($dispatcher);

        $bridge->methodNotAllowed('DELETE', '/users', ['GET', 'POST']);

        $event = $dispatcher->dispatched[0];
        self::assertSame('router.method_not_allowed', $event->name);
        self::assertSame(['GET', 'POST'], $event->payload['allowed']);
    }

    public function testNonDispatcherDoesNotThrow(): void
    {
        $nonDispatcher = new \stdClass();
        $bridge = new EventDispatcherBridge($nonDispatcher);

        $bridge->routeMatched($this->createResult(), 1.0);
        $bridge->routeNotFound('GET', '/', 1.0);
        $bridge->methodNotAllowed('GET', '/', []);

        self::assertTrue(true);
    }

    public function testEventHasTimestamp(): void
    {
        $dispatcher = $this->createDispatcher();
        $bridge = new EventDispatcherBridge($dispatcher);

        $bridge->routeMatched($this->createResult(), 1.0);

        self::assertObjectHasProperty('timestamp', $dispatcher->dispatched[0]);
        self::assertIsInt($dispatcher->dispatched[0]->timestamp);
    }
}
