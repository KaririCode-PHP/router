<?php

declare(strict_types=1);

namespace KaririCode\Router\Tests\Unit\Core;

use KaririCode\Router\Core\MatchResult;
use KaririCode\Router\Core\RouteEntry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MatchResult::class)]
final class MatchResultTest extends TestCase
{
    #[Test]
    public function itExposesTheRouteParametersAndMiddleware(): void
    {
        $route = new RouteEntry(['GET'], '/users/{id}', ['UserController', 'show'], 'users.show');
        $result = new MatchResult($route, ['id' => '42'], ['auth']);

        self::assertSame($route, $result->route);
        self::assertSame('42', $result->parameters->get('id'));
        self::assertSame(['auth'], $result->middleware);
        self::assertSame(['UserController', 'show'], $result->handler());
        self::assertSame('users.show', $result->routeName());
    }

    #[Test]
    public function itDefaultsToNoParametersAndNoMiddleware(): void
    {
        $result = new MatchResult(new RouteEntry(['GET'], '/x', 'h'));

        self::assertCount(0, $result->parameters);
        self::assertSame([], $result->middleware);
        self::assertNull($result->routeName());
    }
}
