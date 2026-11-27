<?php

declare(strict_types=1);

namespace KaririCode\Router\Tests\Unit\Core;

use KaririCode\Router\Core\ArrayRouteLoader;
use KaririCode\Router\Core\RouteEntry;
use KaririCode\Router\Exception\RouteFileException;
use KaririCode\Router\Exception\RouterCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArrayRouteLoader::class)]
final class ArrayRouteLoaderTest extends TestCase
{
    private const string ROUTES = __DIR__ . '/../../Fixture/routes';

    #[Test]
    public function itLoadsRoutesAndNestedGroupsFromAFile(): void
    {
        $routes = (new ArrayRouteLoader())->loadFile(self::ROUTES . '/valid.php');

        self::assertSame(['/health', '/search', '/admin/stats', '/admin/users/{id:int}'], array_map(static fn(RouteEntry $route): string => $route->pattern, $routes));
        self::assertSame(['health', 'search', 'admin.stats', 'admin.users.delete'], array_map(static fn(RouteEntry $route): ?string => $route->name, $routes));
        self::assertSame(['GET', 'POST'], $routes[1]->methods);
        self::assertSame(['SearchController', 'run'], $routes[1]->handler);
        self::assertSame(['throttle'], $routes[1]->middleware);
        self::assertSame(['page' => '1'], $routes[1]->defaults);
        self::assertSame(['auth'], $routes[2]->middleware);
        self::assertSame(['DELETE'], $routes[3]->methods);
        self::assertSame(['auth'], $routes[3]->middleware);
    }

    #[Test]
    public function nameMiddlewareAndDefaultsAreOptional(): void
    {
        $handler = static fn(): null => null;
        $routes = (new ArrayRouteLoader())->load([['GET', '/x', $handler]]);

        self::assertCount(1, $routes);
        self::assertNull($routes[0]->name);
        self::assertSame($handler, $routes[0]->handler);
        self::assertSame([], $routes[0]->middleware);
        self::assertSame([], $routes[0]->defaults);
    }

    #[Test]
    public function aGroupWithoutNamePrefixKeepsTheNames(): void
    {
        $routes = (new ArrayRouteLoader())->load(['admin' => ['group' => '/admin', 'routes' => [['GET', '/a', 'h', 'a']]]]);

        self::assertSame('a', $routes[0]->name);
        self::assertSame('/admin/a', $routes[0]->pattern);
    }

    #[Test]
    public function aMissingFileFails(): void
    {
        $this->expectException(RouteFileException::class);
        $this->expectExceptionCode(RouterCode::RouteFileMissing->value);

        (new ArrayRouteLoader())->loadFile(self::ROUTES . '/absent.php');
    }

    #[Test]
    public function aFileThatReturnsNoArrayFails(): void
    {
        $this->expectException(RouteFileException::class);
        $this->expectExceptionCode(RouterCode::RouteFileInvalid->value);

        (new ArrayRouteLoader())->loadFile(self::ROUTES . '/not-an-array.php');
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, string}>
     */
    public static function invalidDefinitions(): iterable
    {
        yield 'entry is not an array' => [['GET /x'], 'entry 1 of the root is invalid: an entry is an array'];
        yield 'pattern is not a string' => [[['GET', 42, 'h']], 'entry 1 of the root is invalid: a route is'];
        yield 'handler missing' => [[['GET', '/x']], 'a route is'];
        yield 'handler of the wrong shape' => [[['GET', '/x', ['A', 'b', 'c']]], 'a route is'];
        yield 'handler pair of non-strings' => [[['GET', '/x', ['A', 1]]], 'a route is'];
        yield 'handler map' => [[['GET', '/x', ['a' => 'A', 'b' => 'b']]], 'a route is'];
        yield 'name is not a string' => [[['GET', '/x', 'h', 7]], 'a route is'];
        yield 'methods are not strings' => [[[[1], '/x', 'h']], '"methods" is a list of strings'];
        yield 'methods missing' => [[[null, '/x', 'h']], '"methods" is a list of strings'];
        yield 'middleware is not a list' => [[['GET', '/x', 'h', 'middleware' => 'auth']], '"middleware" is a list of strings'];
        yield 'middleware map' => [[['GET', '/x', 'h', 'middleware' => ['a' => 'auth']]], '"middleware" is a list of strings'];
        yield 'defaults of non-strings' => [[['GET', '/x', 'h', 'defaults' => ['page' => 1]]], '"defaults" maps parameter names to strings'];
        yield 'defaults list' => [[['GET', '/x', 'h', 'defaults' => ['1']]], '"defaults" maps parameter names to strings'];
        yield 'defaults not an array' => [[['GET', '/x', 'h', 'defaults' => 'page=1']], '"defaults" maps parameter names to strings'];
        yield 'group prefix not a string' => [[['group' => 1, 'routes' => []]], 'a group has'];
        yield 'group without routes' => [[['group' => '/a']], 'a group has'];
        yield 'group name prefix not a string' => [[['group' => '/a', 'routes' => [], 'name_prefix' => 1]], 'a group has'];
        yield 'group middleware not strings' => [[['group' => '/a', 'routes' => [], 'middleware' => [1]]], '"middleware" is a list of strings'];
        yield 'nested position' => [[['GET', '/ok', 'h'], ['group' => '/admin', 'routes' => [['GET', '/a', 'h'], 'oops']]], 'entry 2 of group "/admin" is invalid'];
    }

    /**
     * @param array<array-key, mixed> $definitions
     */
    #[Test]
    #[DataProvider('invalidDefinitions')]
    public function anInvalidEntryFailsWithItsPosition(array $definitions, string $message): void
    {
        try {
            (new ArrayRouteLoader())->load($definitions);
            self::fail('RouteFileException expected');
        } catch (RouteFileException $e) {
            self::assertSame(RouterCode::InvalidRouteDefinition, $e->errorCode);
            self::assertStringContainsString($message, $e->getMessage());
        }
    }
}
