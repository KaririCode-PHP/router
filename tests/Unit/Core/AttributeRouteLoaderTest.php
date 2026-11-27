<?php

declare(strict_types=1);

namespace KaririCode\Router\Tests\Unit\Core;

use KaririCode\Router\Attribute\Controller;
use KaririCode\Router\Attribute\Route;
use KaririCode\Router\Core\AttributeRouteLoader;
use KaririCode\Router\Core\RouteEntry;
use KaririCode\Router\Tests\Fixture\Controller\PlainController;
use KaririCode\Router\Tests\Fixture\Controller\UserController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AttributeRouteLoader::class)]
#[CoversClass(Controller::class)]
#[CoversClass(Route::class)]
final class AttributeRouteLoaderTest extends TestCase
{
    #[Test]
    public function itReadsEveryRouteOfThePublicMethods(): void
    {
        $routes = (new AttributeRouteLoader())->loadFromClass(UserController::class);

        self::assertSame(
            ['/api/v1/users/', '/api/v1/users/{id:int}', '/api/v1/users/', '/api/v1/users/bulk', '/api/v1/users/export'],
            array_map(static fn(RouteEntry $route): string => $route->pattern, $routes),
        );
        self::assertSame(['users.index', 'users.show', 'users.store', null, null], array_map(static fn(RouteEntry $route): ?string => $route->name, $routes));
        self::assertSame([UserController::class, 'store'], $routes[3]->handler);
    }

    #[Test]
    public function controllerDefaultsApplyAndRouteValuesWin(): void
    {
        [$index, $show, $store] = (new AttributeRouteLoader())->loadFromClass(UserController::class);

        self::assertSame(['GET'], $index->methods);
        self::assertSame(['auth'], $index->middleware);
        self::assertSame('api.example.com', $index->host);
        self::assertSame('https', $index->scheme);

        self::assertSame(['GET', 'HEAD'], $show->methods);
        self::assertSame(['auth', 'etag'], $show->middleware);
        self::assertSame(['format' => 'json'], $show->defaults);
        self::assertSame('admin.example.com', $show->host);
        self::assertSame('wss', $show->scheme);
        self::assertSame([UserController::class, 'show'], $show->handler);

        self::assertSame(['POST'], $store->methods);
    }

    #[Test]
    public function aClassWithoutControllerUsesNoPrefix(): void
    {
        $routes = (new AttributeRouteLoader())->loadFromClass(PlainController::class);

        self::assertCount(1, $routes);
        self::assertSame('/ping', $routes[0]->pattern);
        self::assertSame(['SUBSCRIBE'], $routes[0]->methods);
        self::assertSame('ping', $routes[0]->name);
        self::assertSame([], $routes[0]->middleware);
        self::assertNull($routes[0]->host);
    }

    #[Test]
    public function loadFromClassesJoinsTheClassesInOrder(): void
    {
        $loader = new AttributeRouteLoader();

        self::assertCount(6, $loader->loadFromClasses([PlainController::class, UserController::class]));
        self::assertSame('/ping', $loader->loadFromClasses([PlainController::class, UserController::class])[0]->pattern);
        self::assertSame([], $loader->loadFromClasses([]));
    }

    #[Test]
    public function theRouteAttributeNormalisesItsMethods(): void
    {
        self::assertSame(['GET'], (new Route('/x'))->methods);
        self::assertSame(['UNARY', 'GET'], (new Route('/x', ['unary', 'GET']))->methods);
    }
}
