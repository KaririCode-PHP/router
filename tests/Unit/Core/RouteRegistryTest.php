<?php

declare(strict_types=1);

namespace KaririCode\Router\Tests\Unit\Core;

use KaririCode\Router\Core\RouteEntry;
use KaririCode\Router\Core\RouteRegistry;
use KaririCode\Router\Exception\MethodNotAllowedException;
use KaririCode\Router\Exception\RouteNotFoundException;
use PHPUnit\Framework\TestCase;

final class RouteRegistryTest extends TestCase
{
    private RouteRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new RouteRegistry();
    }

    // ── Basic Matching ───────────────────────────────────────────────────

    public function testMatchStaticRoute(): void
    {
        $this->registry->get('/users', 'UserController::index', 'users.index');
        $result = $this->registry->match('GET', '/users');

        self::assertSame('users.index', $result->routeName());
        self::assertSame('UserController::index', $result->handler());
        self::assertCount(0, $result->parameters);
    }

    public function testMatchParameterizedRoute(): void
    {
        $this->registry->get('/users/{id:int}', 'UserController::show', 'users.show');
        $result = $this->registry->match('GET', '/users/42');

        self::assertSame('42', $result->parameters->get('id'));
        self::assertSame('users.show', $result->routeName());
    }

    public function testMatchMultipleParameters(): void
    {
        $this->registry->get('/posts/{year:int}/{slug:slug}', 'PostController::show');
        $result = $this->registry->match('GET', '/posts/2025/hello-world');

        self::assertSame('2025', $result->parameters->get('year'));
        self::assertSame('hello-world', $result->parameters->get('slug'));
    }

    // ── HTTP Methods ─────────────────────────────────────────────────────

    public function testPostRoute(): void
    {
        $this->registry->post('/users', 'UserController::store');
        $result = $this->registry->match('POST', '/users');
        self::assertSame('UserController::store', $result->handler());
    }

    public function testPutRoute(): void
    {
        $this->registry->put('/users/{id:int}', 'UserController::update');
        $result = $this->registry->match('PUT', '/users/1');
        self::assertSame('1', $result->parameters->get('id'));
    }

    public function testDeleteRoute(): void
    {
        $this->registry->delete('/users/{id:int}', 'UserController::destroy');
        $result = $this->registry->match('DELETE', '/users/1');
        self::assertNotNull($result);
    }

    public function testPatchRoute(): void
    {
        $this->registry->patch('/users/{id:int}', 'UserController::patch');
        $result = $this->registry->match('PATCH', '/users/1');
        self::assertNotNull($result);
    }

    // ── HEAD auto-registration with GET ──────────────────────────────────

    public function testGetAlsoRegistersHead(): void
    {
        $this->registry->get('/health', 'HealthController::check');
        $result = $this->registry->match('HEAD', '/health');
        self::assertNotNull($result);
    }

    // ── 404 Not Found ────────────────────────────────────────────────────

    public function testRouteNotFound(): void
    {
        $this->registry->get('/users', 'handler');

        $this->expectException(RouteNotFoundException::class);
        $this->registry->match('GET', '/nonexistent');
    }

    // ── 405 Method Not Allowed ───────────────────────────────────────────

    public function testMethodNotAllowed(): void
    {
        $this->registry->get('/users', 'handler');

        try {
            $this->registry->match('DELETE', '/users');
            self::fail('Expected MethodNotAllowedException');
        } catch (MethodNotAllowedException $e) {
            self::assertContains('GET', $e->allowedMethods);
            self::assertContains('HEAD', $e->allowedMethods);
        }
    }

    // ── Route Groups ─────────────────────────────────────────────────────

    public function testGroupPrefix(): void
    {
        $this->registry->group('/api/v1', function ($group): void {
            $group->get('/users', 'UserController::index', 'users.index');
            $group->post('/users', 'UserController::store', 'users.store');
        });

        $result = $this->registry->match('GET', '/api/v1/users');
        self::assertSame('users.index', $result->routeName());

        $result = $this->registry->match('POST', '/api/v1/users');
        self::assertSame('users.store', $result->routeName());
    }

    public function testNestedGroups(): void
    {
        $this->registry->group('/api', function ($api): void {
            $api->group('/v1', function ($v1): void {
                $v1->get('/users', 'handler', 'api.v1.users');
            });
        });

        $result = $this->registry->match('GET', '/api/v1/users');
        self::assertSame('api.v1.users', $result->routeName());
    }

    public function testGroupMiddleware(): void
    {
        $this->registry->group('/admin', function ($group): void {
            $group->get('/dashboard', 'AdminController::dashboard', 'admin.dashboard');
        }, middleware: ['AuthMiddleware']);

        $result = $this->registry->match('GET', '/admin/dashboard');
        self::assertContains('AuthMiddleware', $result->middleware);
    }

    public function testGroupNamePrefix(): void
    {
        $this->registry->group('/admin', function ($group): void {
            $group->get('/dashboard', 'handler', 'dashboard');
        }, namePrefix: 'admin.');

        $result = $this->registry->match('GET', '/admin/dashboard');
        self::assertSame('admin.dashboard', $result->routeName());
    }

    // ── Named Routes ─────────────────────────────────────────────────────

    public function testNamedRouteLookup(): void
    {
        $this->registry->get('/users', 'handler', 'users.index');
        $route = $this->registry->namedRoute('users.index');
        self::assertNotNull($route);
        self::assertSame('/users', $route->pattern);
    }

    public function testUnknownNamedRouteReturnsNull(): void
    {
        self::assertNull($this->registry->namedRoute('nonexistent'));
    }

    // ── Route Listing ────────────────────────────────────────────────────

    public function testRoutesReturnsAllRegistered(): void
    {
        $this->registry->get('/a', 'handler1');
        $this->registry->post('/b', 'handler2');
        $this->registry->put('/c/{id:int}', 'handler3');

        self::assertCount(3, $this->registry->routes());
    }

    // ── URI Normalization ────────────────────────────────────────────────

    public function testLeadingSlashNormalized(): void
    {
        $this->registry->get('/users', 'handler');
        $result = $this->registry->match('GET', 'users');
        self::assertNotNull($result);
    }

    // ── Constraint Rejection ─────────────────────────────────────────────

    public function testIntConstraintRejectsNonNumeric(): void
    {
        $this->registry->get('/users/{id:int}', 'handler');

        $this->expectException(RouteNotFoundException::class);
        $this->registry->match('GET', '/users/abc');
    }

    public function testUuidConstraintRejectsInvalid(): void
    {
        $this->registry->get('/items/{uuid:uuid}', 'handler');

        $this->expectException(RouteNotFoundException::class);
        $this->registry->match('GET', '/items/not-a-uuid');
    }

    // ── Protocol-Agnostic Registration (ARFA 1.3 P4) ─────────────────────

    public function testRouteMethodAcceptsArbitraryStrings(): void
    {
        $this->registry->route(['UNARY'], '/user.UserService/GetUser', 'UserService::getUser', 'grpc.user.get');

        $result = $this->registry->match('UNARY', '/user.UserService/GetUser');
        self::assertSame('grpc.user.get', $result->routeName());
    }

    public function testRouteMethodWithParameterizedGrpcPath(): void
    {
        $this->registry->route(['UNARY'], '/user.UserService/GetUser/{id:int}', 'UserService::getUser', 'grpc.user.get');

        $result = $this->registry->match('UNARY', '/user.UserService/GetUser/42');
        self::assertSame('42', $result->parameters->get('id'));
    }

    public function testWebSocketEventRegistration(): void
    {
        $this->registry->route(['SUBSCRIBE'], '/channels/notifications', 'NotificationHandler::handle');

        $result = $this->registry->match('SUBSCRIBE', '/channels/notifications');
        self::assertSame('NotificationHandler::handle', $result->handler());
    }

    public function testCliCommandRegistration(): void
    {
        $this->registry->route(['RUN'], '/migrate/up', 'MigrateCommand::execute', 'cli.migrate');

        $result = $this->registry->match('RUN', '/migrate/up');
        self::assertSame('cli.migrate', $result->routeName());
    }

    public function testMultiProtocolMethodsOnSamePath(): void
    {
        $this->registry->route(['QUERY'], '/graphql/users', 'UserResolver::query', 'gql.users.query');
        $this->registry->route(['MUTATION'], '/graphql/users', 'UserResolver::mutate', 'gql.users.mutation');

        $query = $this->registry->match('QUERY', '/graphql/users');
        self::assertSame('gql.users.query', $query->routeName());

        $mutation = $this->registry->match('MUTATION', '/graphql/users');
        self::assertSame('gql.users.mutation', $mutation->routeName());
    }

    public function testRouteMethodNormalizesToUppercase(): void
    {
        $this->registry->route(['unary'], '/service/method', 'handler', 'test');

        $result = $this->registry->match('UNARY', '/service/method');
        self::assertSame('test', $result->routeName());
    }

    public function testMethodNotAllowedForCustomProtocol(): void
    {
        $this->registry->route(['QUERY'], '/graphql/users', 'handler');

        try {
            $this->registry->match('MUTATION', '/graphql/users');
            self::fail('Expected MethodNotAllowedException');
        } catch (MethodNotAllowedException $e) {
            self::assertContains('QUERY', $e->allowedMethods);
        }
    }
}
