<?php

declare(strict_types=1);

namespace KaririCode\Router\Tests\Unit\Core;

use KaririCode\Router\Core\RouteEntry;
use KaririCode\Router\Core\RouteGroup;
use KaririCode\Router\Core\RouteRegistry;
use KaririCode\Router\Exception\MethodNotAllowedException;
use KaririCode\Router\Exception\RouteCompilationException;
use KaririCode\Router\Exception\RouteNotFoundException;
use KaririCode\Router\Exception\RouterCode;
use KaririCode\Router\Matcher\StaticMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RouteRegistry::class)]
final class RouteRegistryTest extends TestCase
{
    #[Test]
    public function fluentMethodsRegisterTheirMethods(): void
    {
        $router = (new RouteRegistry())
            ->get('/a', 'get', 'a')
            ->post('/b', 'post')
            ->put('/c', 'put')
            ->patch('/d', 'patch')
            ->delete('/e', 'delete', null, ['auth']);

        self::assertSame(
            [['GET', 'HEAD'], ['POST'], ['PUT'], ['PATCH'], ['DELETE']],
            array_map(static fn(RouteEntry $route): array => $route->methods, $router->routes()),
        );
        self::assertSame('get', $router->match('HEAD', '/a')->handler());
        self::assertSame('a', $router->match('GET', '/a')->routeName());
        self::assertSame('put', $router->match('PUT', '/c')->handler());
        self::assertSame('patch', $router->match('PATCH', '/d')->handler());
        self::assertSame(['auth'], $router->match('DELETE', '/e')->middleware);
    }

    #[Test]
    public function routeAcceptsMethodsOfAnyProtocolWithDefaultsAndMetadata(): void
    {
        $router = (new RouteRegistry())->route(['unary'], '/greeter/{name}', 'Greeter', 'greet', ['auth'], ['lang' => 'en'], ['rpc' => true]);
        $result = $router->match('UNARY', '/greeter/ana');

        self::assertSame('Greeter', $result->handler());
        self::assertSame(['lang' => 'en', 'name' => 'ana'], $result->parameters->all());
        self::assertSame(['rpc' => true], $result->route->metadata);
        self::assertSame(['auth'], $result->middleware);
        self::assertSame($result->route, $router->namedRoute('greet'));
    }

    #[Test]
    public function matchAddsTheLeadingSlash(): void
    {
        $router = (new RouteRegistry())->get('/users/{id:int}', 'h');

        self::assertSame('42', $router->match('get', 'users/42')->parameters->get('id'));
    }

    #[Test]
    public function aMissIsNotFoundWhenNoRouteHasThePath(): void
    {
        $router = (new RouteRegistry())->get('/users', 'h');

        try {
            $router->match('GET', '/missing/thing');
            self::fail('RouteNotFoundException expected');
        } catch (RouteNotFoundException $e) {
            self::assertSame(RouterCode::RouteNotFound, $e->errorCode);
            self::assertStringNotContainsString('missing', $e->getMessage());
        }
    }

    #[Test]
    public function aMissIsMethodNotAllowedWhenThePathExistsForOtherMethods(): void
    {
        $router = (new RouteRegistry())->get('/users/{id:int}', 'show')->post('/users/{id:int}', 'update');

        try {
            $router->match('DELETE', '/users/7');
            self::fail('MethodNotAllowedException expected');
        } catch (MethodNotAllowedException $e) {
            self::assertSame(['GET', 'HEAD', 'POST'], $e->allowedMethods);
        }
    }

    #[Test]
    public function groupsRegisterTheirResolvedRoutes(): void
    {
        $router = (new RouteRegistry())->group('/admin', static function (RouteGroup $group): void {
            $group->get('/stats', 'stats', 'stats', ['etag']);
        }, ['auth'], 'admin.');

        $result = $router->match('GET', '/admin/stats');

        self::assertSame('admin.stats', $result->routeName());
        self::assertSame(['auth', 'etag'], $result->middleware);
        self::assertSame('/admin/stats', $router->namedRoute('admin.stats')?->pattern);
    }

    #[Test]
    public function routesAndNamesAreKeptInRegistrationOrder(): void
    {
        $router = (new RouteRegistry())->get('/b', 'b', 'b')->get('/a', 'a');

        self::assertSame(['/b', '/a'], array_map(static fn(RouteEntry $route): string => $route->pattern, $router->routes()));
        self::assertNull($router->namedRoute('a'));
        self::assertSame('/b', $router->namedRoute('b')?->pattern);
    }

    #[Test]
    public function aRegisteredPatternMustStartWithASlash(): void
    {
        $this->expectException(RouteCompilationException::class);
        $this->expectExceptionCode(RouterCode::InvalidPattern->value);

        (new RouteRegistry())->get('users', 'h');
    }

    #[Test]
    public function aSecondRouteForTheSameMethodAndPatternIsRejected(): void
    {
        $router = (new RouteRegistry())->get('/users/{id}', 'show');

        try {
            $router->route(['HEAD'], '/users/{id}', 'other');
            self::fail('RouteCompilationException expected');
        } catch (RouteCompilationException $e) {
            self::assertSame(RouterCode::DuplicateRoute, $e->errorCode);
            self::assertStringContainsString('HEAD "/users/{id}"', $e->getMessage());
        }

        $router->post('/users/{id}', 'update');
        self::assertCount(2, $router->routes(), 'another method on the same pattern is fine');
    }

    #[Test]
    public function aRejectedRouteLeavesTheRegistryUnchanged(): void
    {
        $router = (new RouteRegistry())->get('/a', 'a', 'name');

        try {
            $router->get('/b', 'b', 'name');
        } catch (RouteCompilationException $e) {
            self::assertSame(RouterCode::DuplicateRouteName, $e->errorCode);
        }

        try {
            $router->route(['POST', 'GET'], '/a', 'again');
        } catch (RouteCompilationException $e) {
            self::assertSame(RouterCode::DuplicateRoute, $e->errorCode);
        }

        self::assertCount(1, $router->routes());
        self::assertSame('a', $router->namedRoute('name')?->handler);

        $this->expectException(RouteNotFoundException::class);
        $router->match('GET', '/b');
    }

    #[Test]
    public function aRouteRejectedForItsMethodsDoesNotClaimTheOthers(): void
    {
        $router = (new RouteRegistry())->get('/a', 'a');

        try {
            $router->route(['POST', 'GET'], '/a', 'again');
        } catch (RouteCompilationException) {
        }

        $router->post('/a', 'post');
        self::assertSame('post', $router->match('POST', '/a')->handler());
    }

    #[Test]
    public function aCustomMatcherReceivesTheRoutes(): void
    {
        $matcher = new StaticMatcher();
        $router = new RouteRegistry($matcher);
        $router->get('/health', 'HealthCheck');

        self::assertSame('HealthCheck', $matcher->match('GET', '/health')?->handler());
    }
}
