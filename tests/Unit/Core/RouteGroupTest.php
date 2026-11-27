<?php

declare(strict_types=1);

namespace KaririCode\Router\Tests\Unit\Core;

use KaririCode\Router\Core\RouteEntry;
use KaririCode\Router\Core\RouteGroup;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RouteGroup::class)]
final class RouteGroupTest extends TestCase
{
    #[Test]
    public function shortcutsAddTheirMethodsWithTheGroupApplied(): void
    {
        $group = (new RouteGroup('/api/', ['api'], 'api.'))
            ->get('/a', 'a', 'a')
            ->post('/b', 'b')
            ->put('/c', 'c')
            ->patch('/d', 'd')
            ->delete('/e', 'e')
            ->options('/f', 'f')
            ->any('/g', 'g', null, ['local']);

        $routes = $group->resolve();

        self::assertSame(
            [['GET', 'HEAD'], ['POST'], ['PUT'], ['PATCH'], ['DELETE'], ['OPTIONS'], ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']],
            array_map(static fn(RouteEntry $route): array => $route->methods, $routes),
        );
        self::assertSame(['/api/a', '/api/b', '/api/c', '/api/d', '/api/e', '/api/f', '/api/g'], array_map(static fn(RouteEntry $route): string => $route->pattern, $routes));
        self::assertSame('api.a', $routes[0]->name);
        self::assertNull($routes[1]->name);
        self::assertSame(['api'], $routes[1]->middleware);
        self::assertSame(['api', 'local'], $routes[6]->middleware);
    }

    #[Test]
    public function mapCarriesDefaultsAndMetadata(): void
    {
        $routes = (new RouteGroup('/rpc'))->map(['UNARY'], '/{service}', 'Rpc', 'rpc', ['auth'], ['version' => '1'], ['stream' => false])->resolve();

        self::assertSame(['UNARY'], $routes[0]->methods);
        self::assertSame('/rpc/{service}', $routes[0]->pattern);
        self::assertSame('rpc', $routes[0]->name);
        self::assertSame(['auth'], $routes[0]->middleware);
        self::assertSame(['version' => '1'], $routes[0]->defaults);
        self::assertSame(['stream' => false], $routes[0]->metadata);
    }

    #[Test]
    public function nestedGroupsApplyTheInnerGroupFirst(): void
    {
        $outer = new RouteGroup('/api/v1', ['outer'], 'api.');
        $outer->get('/users', 'users', 'users')
            ->group('/admin', static function (RouteGroup $admin): void {
                $admin->get('/stats', 'stats', 'stats', ['route']);
                $admin->group('/deep', static function (RouteGroup $deep): void {
                    $deep->post('', 'deep', 'deep');
                });
            }, ['inner'], 'admin.')
            ->get('/after', 'after');

        $routes = $outer->resolve();

        self::assertSame(['/api/v1/users', '/api/v1/admin/stats', '/api/v1/admin/deep', '/api/v1/after'], array_map(static fn(RouteEntry $route): string => $route->pattern, $routes));
        self::assertSame('api.admin.stats', $routes[1]->name);
        self::assertSame(['outer', 'inner', 'route'], $routes[1]->middleware);
        self::assertSame('api.admin.deep', $routes[2]->name);
        self::assertSame(['outer', 'inner'], $routes[2]->middleware);
        self::assertNull($routes[3]->name);
    }

    #[Test]
    public function anEmptyGroupResolvesToNothing(): void
    {
        self::assertSame([], (new RouteGroup('/x'))->resolve());
    }
}
