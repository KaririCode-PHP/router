<?php

declare(strict_types=1);

namespace KaririCode\Router\Tests\Integration;

use KaririCode\Router\Cache\PhpFileStore;
use KaririCode\Router\Core\ArrayRouteLoader;
use KaririCode\Router\Core\AttributeRouteLoader;
use KaririCode\Router\Core\MatchStatistics;
use KaririCode\Router\Core\ObservedRouter;
use KaririCode\Router\Core\RouteGroup;
use KaririCode\Router\Core\RouteRegistry;
use KaririCode\Router\Exception\MethodNotAllowedException;
use KaririCode\Router\Exception\RouteNotFoundException;
use KaririCode\Router\Generator\RouteUrlGenerator;
use KaririCode\Router\Matcher\CachedMatcher;
use KaririCode\Router\Matcher\CompositeRouteMatcher;
use KaririCode\Router\Middleware\MiddlewarePipeline;
use KaririCode\Router\Tests\Fixture\Controller\UserController;
use KaririCode\Router\Tests\Fixture\TraceMiddleware;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The router assembled as an application would: attribute, array and fluent
 * routes in one registry, an observed router in front, URL generation,
 * middleware, and a cached table written to a real file and read back by a
 * second matcher, the way a deploy step and the next request would.
 */
#[CoversNothing]
final class FullLifecycleTest extends TestCase
{
    private string $cacheFile;

    protected function setUp(): void
    {
        $this->cacheFile = sys_get_temp_dir() . '/kariricode-router-' . bin2hex(random_bytes(6)) . '.php';
    }

    protected function tearDown(): void
    {
        if (is_file($this->cacheFile)) {
            unlink($this->cacheFile);
        }
    }

    #[Test]
    public function routesFromEverySourceMatchAndGenerate(): void
    {
        $registry = self::application(new CompositeRouteMatcher());
        $statistics = new MatchStatistics();
        $router = new ObservedRouter($registry, $statistics);

        self::assertSame([UserController::class, 'show'], $router->match('GET', '/api/v1/users/42')->handler());
        self::assertSame('admin.users.delete', $router->match('DELETE', '/admin/users/9')->routeName());
        self::assertSame(['page' => '1'], $router->match('POST', '/search')->parameters->all());
        self::assertSame('api.admin.stats', $router->match('GET', '/api/admin/stats')->routeName());

        try {
            $router->match('PUT', '/api/v1/users/42');
            self::fail('MethodNotAllowedException expected');
        } catch (MethodNotAllowedException $e) {
            self::assertSame(['GET', 'HEAD'], $e->allowedMethods);
        }

        try {
            $router->match('GET', '/nowhere');
            self::fail('RouteNotFoundException expected');
        } catch (RouteNotFoundException) {
        }

        self::assertSame(4, $statistics->matches);
        self::assertSame(1, $statistics->methodNotAllowed);
        self::assertSame(1, $statistics->notFound);

        $generator = new RouteUrlGenerator($registry);
        self::assertSame('/api/v1/users/42', $generator->generate('users.show', ['id' => 42]));
        self::assertSame('/admin/users/9?force=1', $generator->generate('admin.users.delete', ['id' => 9, 'force' => 1]));
    }

    #[Test]
    public function aMatchRunsThroughTheRouteMiddleware(): void
    {
        $result = self::application(new CompositeRouteMatcher())->match('GET', '/api/admin/stats');
        $middleware = array_map(static fn(string $label): TraceMiddleware => new TraceMiddleware($label), $result->middleware);

        $output = (new MiddlewarePipeline())->execute(
            $middleware,
            static fn(array $context): string => (is_string($context['trace'] ?? null) ? $context['trace'] : '') . 'stats',
            ['trace' => ''],
        );

        self::assertSame('api>admin>stats<admin<api', $output);
    }

    #[Test]
    public function aWarmedFileServesTheNextProcess(): void
    {
        $deploy = new CachedMatcher(new CompositeRouteMatcher(), new PhpFileStore($this->cacheFile));
        self::application($deploy);
        $deploy->warm();

        $matcher = new CachedMatcher(new CompositeRouteMatcher(), new PhpFileStore($this->cacheFile));
        $router = new RouteRegistry($matcher);

        self::assertTrue($matcher->isCached());
        self::assertSame([UserController::class, 'show'], $router->match('GET', '/api/v1/users/7')->handler());
        self::assertSame(['format' => 'json', 'id' => '7'], $router->match('HEAD', '/api/v1/users/7')->parameters->all());
        self::assertSame('health', $router->match('GET', '/health')->routeName());

        $this->expectException(MethodNotAllowedException::class);
        $router->match('PATCH', '/health');
    }

    private static function application(CompositeRouteMatcher|CachedMatcher $matcher): RouteRegistry
    {
        $registry = new RouteRegistry($matcher);

        foreach ((new AttributeRouteLoader())->loadFromClass(UserController::class) as $route) {
            $registry->addRoute($route);
        }

        foreach ((new ArrayRouteLoader())->loadFile(__DIR__ . '/../Fixture/routes/valid.php') as $route) {
            $registry->addRoute($route);
        }

        return $registry->group('/api', static function (RouteGroup $api): void {
            $api->group('/admin', static function (RouteGroup $admin): void {
                $admin->get('/stats', ['AdminController', 'stats'], 'stats');
            }, ['admin'], 'admin.');
        }, ['api'], 'api.');
    }
}
