<?php

declare(strict_types=1);

namespace KaririCode\Router\Tests\Unit\Core;

use KaririCode\Router\Core\RouteEntry;
use KaririCode\Router\Exception\RouteCompilationException;
use KaririCode\Router\Exception\RouterCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RouteEntry::class)]
final class RouteEntryTest extends TestCase
{
    #[Test]
    public function itKeepsEveryFieldAndCompilesThePattern(): void
    {
        $handler = static fn(): string => 'ok';
        $route = new RouteEntry(
            methods: ['get', 'HEAD', 'Get'],
            pattern: '/users/{id:int}',
            handler: $handler,
            name: 'users.show',
            middleware: ['auth'],
            defaults: ['format' => 'json'],
            metadata: ['rate' => 10],
            host: 'api.example.com',
            scheme: 'https',
        );

        self::assertSame(['GET', 'HEAD'], $route->methods);
        self::assertSame('/users/{id:int}', $route->pattern);
        self::assertSame('/users/{id:int}', $route->compiledPattern->raw);
        self::assertSame($handler, $route->handler);
        self::assertSame('users.show', $route->name);
        self::assertSame(['auth'], $route->middleware);
        self::assertSame(['format' => 'json'], $route->defaults);
        self::assertSame(['rate' => 10], $route->metadata);
        self::assertSame('api.example.com', $route->host);
        self::assertSame('https', $route->scheme);
    }

    #[Test]
    public function repeatedMethodsCollapseIntoAList(): void
    {
        self::assertSame(['GET', 'POST'], (new RouteEntry(['GET', 'get', 'POST'], '/x', 'h'))->methods);
    }

    #[Test]
    public function methodsOfAnyProtocolAreAccepted(): void
    {
        self::assertSame(['UNARY', 'SERVER_STREAMING', 'M-SEARCH'], (new RouteEntry(['unary', 'server_streaming', 'M-SEARCH'], '/x', 'h'))->methods);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function invalidMethods(): iterable
    {
        yield 'none' => [[]];
        yield 'empty string' => [['']];
        yield 'with a space' => [['GET POST']];
        yield 'starts with a digit' => [['1GET']];
        yield 'with a slash' => [['GET/1']];
        yield 'one bad among good' => [['GET', 'P{ST']];
    }

    /**
     * @param list<string> $methods
     */
    #[Test]
    #[DataProvider('invalidMethods')]
    public function invalidMethodsAreRejected(array $methods): void
    {
        $this->expectException(RouteCompilationException::class);
        $this->expectExceptionCode(RouterCode::InvalidMethod->value);

        new RouteEntry($methods, '/x', 'h');
    }

    /**
     * @return iterable<string, array{string|array{0: string, 1: string}}>
     */
    public static function invalidHandlers(): iterable
    {
        yield 'empty string' => [''];
        yield 'empty class' => [['', 'show']];
        yield 'empty method' => [['UserController', '']];
    }

    /**
     * @param string|array{0: string, 1: string} $handler
     */
    #[Test]
    #[DataProvider('invalidHandlers')]
    public function emptyHandlersAreRejected(string|array $handler): void
    {
        $this->expectException(RouteCompilationException::class);
        $this->expectExceptionCode(RouterCode::InvalidHandler->value);

        new RouteEntry(['GET'], '/x', $handler);
    }

    #[Test]
    public function anInvalidPatternIsRejected(): void
    {
        $this->expectException(RouteCompilationException::class);
        $this->expectExceptionCode(RouterCode::InvalidPattern->value);

        new RouteEntry(['GET'], '/x/{', 'h');
    }

    #[Test]
    public function matchUriAddsDefaultsThePathDidNotProvide(): void
    {
        $route = new RouteEntry(['GET'], '/users/{id:int}', 'h', defaults: ['format' => 'json', 'id' => '0']);

        self::assertSame(['format' => 'json', 'id' => '7'], $route->matchUri('/users/7'));
        self::assertNull($route->matchUri('/users/x'));
        self::assertSame(['format' => 'json', 'id' => '9'], $route->parametersFrom(['9']));
    }

    #[Test]
    public function acceptsMethodIgnoresCase(): void
    {
        $route = new RouteEntry(['GET', 'HEAD'], '/x', 'h');

        self::assertTrue($route->acceptsMethod('get'));
        self::assertTrue($route->acceptsMethod('HEAD'));
        self::assertFalse($route->acceptsMethod('POST'));
    }

    #[Test]
    public function withersReturnCopiesAndKeepTheOtherFields(): void
    {
        $route = new RouteEntry(['GET'], '/stats', ['Admin', 'stats'], 'stats', ['etag', 'gzip'], ['x' => 'y'], ['k' => 1], 'h.test', 'https');

        $prefixed = $route->withPrefix('/admin/');
        $wrapped = $route->withPrependedMiddleware(['auth', 'csrf']);
        $named = $route->withNamePrefix('admin.');

        self::assertSame('/admin/stats', $prefixed->pattern);
        self::assertSame('/admin/stats', $prefixed->compiledPattern->raw);
        self::assertSame(['auth', 'csrf', 'etag', 'gzip'], $wrapped->middleware);
        self::assertSame('admin.stats', $named->name);
        self::assertSame('/stats', $route->pattern);
        self::assertSame(['etag', 'gzip'], $route->middleware);
        self::assertSame('stats', $route->name);

        foreach ([$prefixed, $wrapped, $named] as $copy) {
            self::assertSame(['GET'], $copy->methods);
            self::assertSame(['Admin', 'stats'], $copy->handler);
            self::assertSame(['x' => 'y'], $copy->defaults);
            self::assertSame(['k' => 1], $copy->metadata);
            self::assertSame('h.test', $copy->host);
            self::assertSame('https', $copy->scheme);
        }

        self::assertSame('stats', $prefixed->name);
        self::assertSame(['etag', 'gzip'], $named->middleware);
        self::assertSame('/stats', $wrapped->pattern);
    }

    #[Test]
    public function anUnnamedRouteStaysUnnamed(): void
    {
        $route = new RouteEntry(['GET'], '/x', 'h');

        self::assertSame($route, $route->withNamePrefix('admin.'));
    }
}
