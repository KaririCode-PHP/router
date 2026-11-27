<?php

declare(strict_types=1);

namespace KaririCode\Router\Tests\Integration;

use KaririCode\Router\Attribute\Controller;
use KaririCode\Router\Attribute\Route;
use KaririCode\Router\Core\AttributeRouteLoader;
use KaririCode\Router\Core\RouteRegistry;
use KaririCode\Router\Enum\HttpMethod;
use KaririCode\Router\Generator\RouteUrlGenerator;
use KaririCode\Router\Integration\ObservabilityBridge;
use PHPUnit\Framework\TestCase;

// ── Fixtures (backward compat — HttpMethod enum) ─────────────────────

#[Controller('/api/v1/users', middleware: ['AuthMiddleware'], namePrefix: 'users.')]
class UserFixtureController
{
    #[Route('/', method: HttpMethod::Get, name: 'index')]
    public function index(): void {}

    #[Route('/{id:int}', method: HttpMethod::Get, name: 'show')]
    public function show(int $id): void {}

    #[Route('/', method: HttpMethod::Post, name: 'store')]
    public function store(): void {}

    #[Route('/{id:int}', method: HttpMethod::Put, name: 'update')]
    public function update(int $id): void {}

    #[Route('/{id:int}', method: HttpMethod::Delete, name: 'destroy')]
    public function destroy(int $id): void {}
}

// ── Fixtures (string-based — protocol-agnostic) ──────────────────────

#[Controller('/api/v2/items', middleware: ['TokenAuth'], namePrefix: 'items.')]
class ItemFixtureController
{
    #[Route('/', method: 'GET', name: 'index')]
    public function index(): void {}

    #[Route('/{id:int}', method: 'GET', name: 'show')]
    public function show(int $id): void {}

    #[Route('/', method: 'POST', name: 'store')]
    public function store(): void {}
}

// ── Tests ────────────────────────────────────────────────────────────

final class FullLifecycleTest extends TestCase
{
    public function testAttributeBasedRoutingFullLifecycle(): void
    {
        $registry = new RouteRegistry();
        $loader = new AttributeRouteLoader();

        foreach ($loader->loadFromClass(UserFixtureController::class) as $route) {
            $registry->addRoute($route);
        }

        $index = $registry->match('GET', '/api/v1/users/');
        self::assertSame('users.index', $index->routeName());
        self::assertContains('AuthMiddleware', $index->middleware);

        $show = $registry->match('GET', '/api/v1/users/42');
        self::assertSame('users.show', $show->routeName());
        self::assertSame('42', $show->parameters->get('id'));

        $store = $registry->match('POST', '/api/v1/users/');
        self::assertSame('users.store', $store->routeName());

        $update = $registry->match('PUT', '/api/v1/users/1');
        self::assertSame('users.update', $update->routeName());

        $delete = $registry->match('DELETE', '/api/v1/users/1');
        self::assertSame('users.destroy', $delete->routeName());
    }

    public function testStringBasedAttributeRouting(): void
    {
        $registry = new RouteRegistry();
        $loader = new AttributeRouteLoader();

        foreach ($loader->loadFromClass(ItemFixtureController::class) as $route) {
            $registry->addRoute($route);
        }

        $index = $registry->match('GET', '/api/v2/items/');
        self::assertSame('items.index', $index->routeName());
        self::assertContains('TokenAuth', $index->middleware);

        $show = $registry->match('GET', '/api/v2/items/99');
        self::assertSame('items.show', $show->routeName());
        self::assertSame('99', $show->parameters->get('id'));

        $store = $registry->match('POST', '/api/v2/items/');
        self::assertSame('items.store', $store->routeName());
    }

    public function testUrlGenerationFromAttributeRoutes(): void
    {
        $registry = new RouteRegistry();
        $loader = new AttributeRouteLoader();

        foreach ($loader->loadFromClass(UserFixtureController::class) as $route) {
            $registry->addRoute($route);
        }

        $generator = new RouteUrlGenerator($registry);
        self::assertSame('/api/v1/users/', $generator->generate('users.index'));
        self::assertSame('/api/v1/users/42', $generator->generate('users.show', ['id' => 42]));
    }

    public function testFluentApiWithGroupsAndUrlGeneration(): void
    {
        $registry = new RouteRegistry();

        $registry->group('/api/v2', function ($api): void {
            $api->get('/posts', 'PostController::index', 'posts.index');
            $api->get('/posts/{id:int}', 'PostController::show', 'posts.show');
            $api->group('/admin', function ($admin): void {
                $admin->get('/stats', 'AdminController::stats', 'admin.stats');
            }, middleware: ['AdminAuth'], namePrefix: 'admin.');
        }, namePrefix: 'api.');

        $result = $registry->match('GET', '/api/v2/posts');
        self::assertSame('api.posts.index', $result->routeName());

        $result = $registry->match('GET', '/api/v2/posts/99');
        self::assertSame('99', $result->parameters->get('id'));

        $result = $registry->match('GET', '/api/v2/admin/stats');
        self::assertSame('api.admin.admin.stats', $result->routeName());
        self::assertContains('AdminAuth', $result->middleware);

        $gen = new RouteUrlGenerator($registry);
        self::assertSame('/api/v2/posts', $gen->generate('api.posts.index'));
        self::assertSame('/api/v2/posts/5', $gen->generate('api.posts.show', ['id' => 5]));
    }

    public function testObservabilityBridgeTracksMetrics(): void
    {
        $registry = new RouteRegistry();
        $registry->get('/users', 'handler', 'users.index');

        $obs = new ObservabilityBridge();

        $result = $registry->match('GET', '/users');
        $obs->recordMatch($result, 0.05);
        $obs->recordMatch($result, 0.03);
        $obs->recordMiss('GET', '/unknown', 0.10);

        $snap = $obs->snapshot();
        self::assertSame(2, $snap['matches']);
        self::assertSame(1, $snap['misses']);
        self::assertGreaterThan(0, $snap['avg_match_ms']);
    }

    public function testMixedStaticAndParameterizedRoutes(): void
    {
        $registry = new RouteRegistry();

        for ($i = 0; $i < 100; ++$i) {
            $registry->get("/static/{$i}", "handler_{$i}");
        }
        for ($i = 0; $i < 50; ++$i) {
            $registry->get("/dynamic/{$i}/{id:int}", "dyn_handler_{$i}");
        }

        $result = $registry->match('GET', '/static/50');
        self::assertSame('handler_50', $result->handler());

        $result = $registry->match('GET', '/dynamic/25/999');
        self::assertSame('999', $result->parameters->get('id'));
    }

    // ── Protocol-Agnostic Lifecycle (ARFA 1.3 P4) ────────────────────────

    public function testGrpcRoutingLifecycle(): void
    {
        $registry = new RouteRegistry();

        $registry->route(['UNARY'], '/user.UserService/GetUser/{id:int}', 'UserService::getUser', 'grpc.user.get');
        $registry->route(['SERVER_STREAMING'], '/user.UserService/ListUsers', 'UserService::listUsers', 'grpc.user.list');

        $get = $registry->match('UNARY', '/user.UserService/GetUser/42');
        self::assertSame('grpc.user.get', $get->routeName());
        self::assertSame('42', $get->parameters->get('id'));

        $list = $registry->match('SERVER_STREAMING', '/user.UserService/ListUsers');
        self::assertSame('grpc.user.list', $list->routeName());
    }

    public function testWebSocketRoutingLifecycle(): void
    {
        $registry = new RouteRegistry();

        $registry->route(['SUBSCRIBE'], '/channels/{channel:slug}', 'ChannelHandler::subscribe', 'ws.subscribe');
        $registry->route(['PUBLISH'], '/channels/{channel:slug}', 'ChannelHandler::publish', 'ws.publish');

        $sub = $registry->match('SUBSCRIBE', '/channels/notifications');
        self::assertSame('ws.subscribe', $sub->routeName());
        self::assertSame('notifications', $sub->parameters->get('channel'));

        $pub = $registry->match('PUBLISH', '/channels/notifications');
        self::assertSame('ws.publish', $pub->routeName());
    }

    public function testCliRoutingLifecycle(): void
    {
        $registry = new RouteRegistry();

        $registry->group('/cli', function ($cli): void {
            $cli->map(['RUN'], '/migrate/up', 'MigrateCommand::up', 'migrate.up');
            $cli->map(['RUN'], '/migrate/down/{steps:int}', 'MigrateCommand::down', 'migrate.down');
            $cli->map(['RUN'], '/cache/clear', 'CacheCommand::clear', 'cache.clear');
        }, namePrefix: 'cli.');

        $up = $registry->match('RUN', '/cli/migrate/up');
        self::assertSame('cli.migrate.up', $up->routeName());

        $down = $registry->match('RUN', '/cli/migrate/down/3');
        self::assertSame('cli.migrate.down', $down->routeName());
        self::assertSame('3', $down->parameters->get('steps'));
    }

    public function testMixedProtocolsCoexist(): void
    {
        $registry = new RouteRegistry();

        // HTTP
        $registry->get('/api/users/{id:int}', 'UserController::show', 'http.user.show');

        // gRPC
        $registry->route(['UNARY'], '/user.UserService/GetUser/{id:int}', 'GrpcUserService::get', 'grpc.user.get');

        // WebSocket
        $registry->route(['SUBSCRIBE'], '/ws/users/{id:int}', 'WsUserHandler::subscribe', 'ws.user.sub');

        // CLI
        $registry->route(['RUN'], '/cli/users/sync', 'SyncCommand::execute', 'cli.user.sync');

        // All coexist independently
        self::assertSame('http.user.show', $registry->match('GET', '/api/users/1')->routeName());
        self::assertSame('grpc.user.get', $registry->match('UNARY', '/user.UserService/GetUser/1')->routeName());
        self::assertSame('ws.user.sub', $registry->match('SUBSCRIBE', '/ws/users/1')->routeName());
        self::assertSame('cli.user.sync', $registry->match('RUN', '/cli/users/sync')->routeName());

        self::assertCount(4, $registry->routes());
    }

    public function testProtocolAgnosticUrlGeneration(): void
    {
        $registry = new RouteRegistry();
        $registry->route(['UNARY'], '/user.UserService/GetUser/{id:int}', 'handler', 'grpc.user.get');

        $gen = new RouteUrlGenerator($registry);
        self::assertSame('/user.UserService/GetUser/42', $gen->generate('grpc.user.get', ['id' => 42]));
    }
}
