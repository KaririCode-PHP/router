<?php

declare(strict_types=1);

namespace KaririCode\Router\Tests\Unit\Core;

use KaririCode\Router\Attribute\Controller;
use KaririCode\Router\Attribute\Route;
use KaririCode\Router\Core\AttributeRouteLoader;
use PHPUnit\Framework\TestCase;

// ── Test Fixtures ────────────────────────────────────────────────────

#[Controller('/api/users', middleware: ['AuthMiddleware'], namePrefix: 'users.')]
class StubUserController
{
    #[Route('/list', method: 'GET', name: 'list')]
    public function list(): void {}

    #[Route('/{id}', method: 'GET', name: 'show')]
    public function show(int $id): void {}

    #[Route('/', method: 'POST', name: 'create')]
    public function create(): void {}
}

class StubNoControllerAttr
{
    #[Route('/standalone', method: 'GET', name: 'standalone')]
    public function index(): void {}
}

#[Controller('/admin', host: 'admin.example.com', scheme: 'https')]
class StubHostController
{
    #[Route('/dashboard', method: 'GET', name: 'dashboard')]
    public function dashboard(): void {}

    #[Route('/settings', method: ['GET', 'POST'], name: 'settings')]
    public function settings(): void {}
}

// ── Tests ────────────────────────────────────────────────────────────

final class AttributeRouteLoaderTest extends TestCase
{
    private AttributeRouteLoader $loader;

    protected function setUp(): void
    {
        $this->loader = new AttributeRouteLoader();
    }

    // ── Basic Discovery ──────────────────────────────────────────────────

    public function testLoadsRoutesFromControllerClass(): void
    {
        $routes = $this->loader->loadFromClass(StubUserController::class);

        self::assertCount(3, $routes);
    }

    public function testPrefixAppliedToRoutePatterns(): void
    {
        $routes = $this->loader->loadFromClass(StubUserController::class);

        $patterns = array_map(fn ($r) => $r->pattern, $routes);
        self::assertContains('/api/users/list', $patterns);
        self::assertContains('/api/users/{id}', $patterns);
        self::assertContains('/api/users/', $patterns);
    }

    public function testNamePrefixApplied(): void
    {
        $routes = $this->loader->loadFromClass(StubUserController::class);

        $names = array_map(fn ($r) => $r->name, $routes);
        self::assertContains('users.list', $names);
        self::assertContains('users.show', $names);
        self::assertContains('users.create', $names);
    }

    public function testMiddlewareInherited(): void
    {
        $routes = $this->loader->loadFromClass(StubUserController::class);

        foreach ($routes as $route) {
            self::assertContains('AuthMiddleware', $route->middleware);
        }
    }

    public function testMethodsExtracted(): void
    {
        $routes = $this->loader->loadFromClass(StubUserController::class);

        $byName = [];
        foreach ($routes as $r) {
            $byName[$r->name] = $r;
        }

        self::assertSame(['GET'], $byName['users.list']->methods);
        self::assertSame(['POST'], $byName['users.create']->methods);
    }

    // ── Without Controller Attribute ─────────────────────────────────────

    public function testClassWithoutControllerAttribute(): void
    {
        $routes = $this->loader->loadFromClass(StubNoControllerAttr::class);

        self::assertCount(1, $routes);
        self::assertSame('/standalone', $routes[0]->pattern);
        self::assertSame('standalone', $routes[0]->name);
        self::assertSame([], $routes[0]->middleware);
    }

    // ── Host and Scheme Inheritance ──────────────────────────────────────

    public function testHostInheritedFromController(): void
    {
        $routes = $this->loader->loadFromClass(StubHostController::class);

        foreach ($routes as $route) {
            self::assertSame('admin.example.com', $route->host);
            self::assertSame('https', $route->scheme);
        }
    }

    public function testMultiMethodRoute(): void
    {
        $routes = $this->loader->loadFromClass(StubHostController::class);

        $settings = null;
        foreach ($routes as $r) {
            if ($r->name === 'settings') {
                $settings = $r;
            }
        }

        self::assertNotNull($settings);
        self::assertSame(['GET', 'POST'], $settings->methods);
    }

    // ── loadFromClasses (batch) ──────────────────────────────────────────

    public function testLoadFromMultipleClasses(): void
    {
        $routes = $this->loader->loadFromClasses([
            StubUserController::class,
            StubNoControllerAttr::class,
        ]);

        self::assertCount(4, $routes);
    }

    public function testEmptyClassListReturnsEmpty(): void
    {
        $routes = $this->loader->loadFromClasses([]);
        self::assertSame([], $routes);
    }

    // ── Handler Format ───────────────────────────────────────────────────

    public function testHandlerIsClassMethodPair(): void
    {
        $routes = $this->loader->loadFromClass(StubUserController::class);

        foreach ($routes as $route) {
            self::assertIsArray($route->handler);
            self::assertSame(StubUserController::class, $route->handler[0]);
            self::assertIsString($route->handler[1]);
        }
    }
}
