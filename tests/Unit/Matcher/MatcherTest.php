<?php

declare(strict_types=1);

namespace KaririCode\Router\Tests\Unit\Matcher;

use KaririCode\Router\Core\RouteEntry;
use KaririCode\Router\Matcher\StaticMatcher;
use KaririCode\Router\Matcher\RegexMatcher;
use KaririCode\Router\Matcher\CompositeRouteMatcher;
use PHPUnit\Framework\TestCase;

final class MatcherTest extends TestCase
{
    // ── StaticMatcher ────────────────────────────────────────────────────

    public function testStaticMatcherMatchesLiteralPaths(): void
    {
        $matcher = new StaticMatcher();
        $route = new RouteEntry(['GET'], '/health', 'handler', 'health');
        $matcher->add($route);

        $result = $matcher->match('GET', '/health');
        self::assertNotNull($result);
        self::assertSame('health', $result->routeName());
    }

    public function testStaticMatcherIgnoresParameterizedRoutes(): void
    {
        $matcher = new StaticMatcher();
        $route = new RouteEntry(['GET'], '/users/{id}', 'handler');
        $matcher->add($route);

        self::assertNull($matcher->match('GET', '/users/1'));
    }

    public function testStaticMatcherAllowedMethods(): void
    {
        $matcher = new StaticMatcher();
        $matcher->add(new RouteEntry(['GET', 'HEAD'], '/users', 'get'));
        $matcher->add(new RouteEntry(['POST'], '/users', 'post'));

        $allowed = $matcher->allowedMethods('/users');
        self::assertContains('GET', $allowed);
        self::assertContains('HEAD', $allowed);
        self::assertContains('POST', $allowed);
    }

    // ── RegexMatcher ─────────────────────────────────────────────────────

    public function testRegexMatcherMatchesSingleParam(): void
    {
        $matcher = new RegexMatcher();
        $route = new RouteEntry(['GET'], '/users/{id:int}', 'handler', 'users.show');
        $matcher->add($route);

        $result = $matcher->match('GET', '/users/42');
        self::assertNotNull($result);
        self::assertSame('42', $result->parameters->get('id'));
    }

    public function testRegexMatcherRejectsConstraintViolation(): void
    {
        $matcher = new RegexMatcher();
        $matcher->add(new RouteEntry(['GET'], '/users/{id:int}', 'handler'));

        self::assertNull($matcher->match('GET', '/users/abc'));
    }

    public function testRegexMatcherMultipleRoutes(): void
    {
        $matcher = new RegexMatcher();
        $matcher->add(new RouteEntry(['GET'], '/users/{id:int}', 'show', 'users.show'));
        $matcher->add(new RouteEntry(['GET'], '/posts/{slug:slug}', 'post', 'posts.show'));

        $r1 = $matcher->match('GET', '/users/1');
        self::assertSame('users.show', $r1?->routeName());

        $r2 = $matcher->match('GET', '/posts/hello-world');
        self::assertSame('posts.show', $r2?->routeName());
    }

    public function testRegexMatcherAllowedMethods(): void
    {
        $matcher = new RegexMatcher();
        $matcher->add(new RouteEntry(['GET'], '/users/{id}', 'get'));
        $matcher->add(new RouteEntry(['DELETE'], '/users/{id}', 'delete'));

        $allowed = $matcher->allowedMethods('/users/1');
        self::assertContains('GET', $allowed);
        self::assertContains('DELETE', $allowed);
    }

    // ── CompositeRouteMatcher ────────────────────────────────────────────

    public function testCompositeMatcherStaticFastPath(): void
    {
        $matcher = new CompositeRouteMatcher();
        $matcher->add(new RouteEntry(['GET'], '/health', 'handler', 'health'));
        $matcher->add(new RouteEntry(['GET'], '/users/{id}', 'handler', 'users.show'));

        $result = $matcher->match('GET', '/health');
        self::assertSame('health', $result?->routeName());

        $result = $matcher->match('GET', '/users/42');
        self::assertSame('users.show', $result?->routeName());
    }

    public function testCompositeMatcherReturnsNullForNoMatch(): void
    {
        $matcher = new CompositeRouteMatcher();
        $matcher->add(new RouteEntry(['GET'], '/users', 'handler'));

        self::assertNull($matcher->match('GET', '/nonexistent'));
    }

    // ── Protocol-Agnostic Matching (ARFA 1.3 P4) ─────────────────────────

    public function testGrpcMethodRouting(): void
    {
        $matcher = new CompositeRouteMatcher();
        $matcher->add(new RouteEntry(['UNARY'], '/user.UserService/GetUser', 'UserService::getUser', 'grpc.user.get'));

        $result = $matcher->match('UNARY', '/user.UserService/GetUser');
        self::assertNotNull($result);
        self::assertSame('grpc.user.get', $result->routeName());
    }

    public function testWebSocketEventRouting(): void
    {
        $matcher = new CompositeRouteMatcher();
        $matcher->add(new RouteEntry(['SUBSCRIBE'], '/channels/notifications', 'NotificationHandler::handle', 'ws.notifications'));

        $result = $matcher->match('SUBSCRIBE', '/channels/notifications');
        self::assertNotNull($result);
        self::assertSame('ws.notifications', $result->routeName());
    }

    public function testGraphQlOperationRouting(): void
    {
        $matcher = new RegexMatcher();
        $matcher->add(new RouteEntry(['QUERY'], '/graphql/users/{id:int}', 'UserResolver::resolve', 'gql.user'));

        $result = $matcher->match('QUERY', '/graphql/users/42');
        self::assertNotNull($result);
        self::assertSame('42', $result->parameters->get('id'));
    }

    public function testCliCommandRouting(): void
    {
        $matcher = new CompositeRouteMatcher();
        $matcher->add(new RouteEntry(['RUN'], '/migrate/up', 'MigrateCommand::execute', 'cli.migrate.up'));

        $result = $matcher->match('RUN', '/migrate/up');
        self::assertNotNull($result);
        self::assertSame('cli.migrate.up', $result->routeName());
    }

    public function testMethodCaseInsensitiveMatching(): void
    {
        $matcher = new StaticMatcher();
        $matcher->add(new RouteEntry(['get'], '/health', 'handler'));

        // RouteEntry normalizes to uppercase, so match with uppercase
        $result = $matcher->match('GET', '/health');
        self::assertNotNull($result);
    }
}
