<?php

declare(strict_types=1);

namespace KaririCode\Router\Tests\Unit\Integration;

use KaririCode\Router\Core\RouteRegistry;
use KaririCode\Router\Integration\ConfiguratorBridge;
use PHPUnit\Framework\TestCase;

final class ConfiguratorBridgeTest extends TestCase
{
    public function testLoadSimpleRoutes(): void
    {
        $bridge = new ConfiguratorBridge();
        $registry = new RouteRegistry();

        $bridge->loadInto($registry, [
            ['GET', '/users', [self::class, 'setUp'], 'users.index'],
            ['POST', '/users', [self::class, 'setUp'], 'users.store'],
        ]);

        self::assertCount(2, $registry->routes());
    }

    public function testLoadGroupedRoutes(): void
    {
        $bridge = new ConfiguratorBridge();
        $registry = new RouteRegistry();

        $bridge->loadInto($registry, [
            ['group' => '/admin', 'middleware' => ['AdminAuth'], 'routes' => [
                ['GET', '/dashboard', [self::class, 'setUp'], 'admin.dashboard'],
                ['GET', '/settings', [self::class, 'setUp'], 'admin.settings'],
            ]],
        ]);

        $routes = $registry->routes();
        self::assertCount(2, $routes);
        self::assertSame('/admin/dashboard', $routes[0]->pattern);
        self::assertSame('/admin/settings', $routes[1]->pattern);
        self::assertContains('AdminAuth', $routes[0]->middleware);
    }

    public function testLoadMultiMethodRoute(): void
    {
        $bridge = new ConfiguratorBridge();
        $registry = new RouteRegistry();

        $bridge->loadInto($registry, [
            ['GET|POST', '/search', [self::class, 'setUp'], 'search'],
        ]);

        $routes = $registry->routes();
        self::assertCount(1, $routes);
        self::assertSame(['GET', 'POST'], $routes[0]->methods);
    }

    public function testLoadFileThrowsForMissingFile(): void
    {
        $bridge = new ConfiguratorBridge();
        $registry = new RouteRegistry();

        $this->expectException(\InvalidArgumentException::class);
        $bridge->loadFile($registry, '/nonexistent/routes.php');
    }

    public function testLoadRouteThrowsForMissingHandler(): void
    {
        $bridge = new ConfiguratorBridge();
        $registry = new RouteRegistry();

        $this->expectException(\InvalidArgumentException::class);
        $bridge->loadInto($registry, [
            ['GET', '/no-handler'],
        ]);
    }

    public function testLoadFileFromPhpArray(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'routes_');
        $class = self::class;
        file_put_contents($tmpFile, "<?php return [['GET', '/from-file', ['{$class}', 'setUp'], 'from.file']];");

        try {
            $bridge = new ConfiguratorBridge();
            $registry = new RouteRegistry();
            $bridge->loadFile($registry, $tmpFile);

            self::assertCount(1, $registry->routes());
            self::assertSame('/from-file', $registry->routes()[0]->pattern);
        } finally {
            @unlink($tmpFile);
        }
    }

    public function testRouteNamesPreserved(): void
    {
        $bridge = new ConfiguratorBridge();
        $registry = new RouteRegistry();

        $bridge->loadInto($registry, [
            ['GET', '/named', [self::class, 'setUp'], 'my.route.name'],
        ]);

        self::assertSame('my.route.name', $registry->routes()[0]->name);
    }

    public function testMethodArrayNotation(): void
    {
        $bridge = new ConfiguratorBridge();
        $registry = new RouteRegistry();

        $bridge->loadInto($registry, [
            [['get', 'post', 'put'], '/multi', [self::class, 'setUp']],
        ]);

        self::assertSame(['GET', 'POST', 'PUT'], $registry->routes()[0]->methods);
    }
}
