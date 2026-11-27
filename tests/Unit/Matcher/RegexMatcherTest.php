<?php

declare(strict_types=1);

namespace KaririCode\Router\Tests\Unit\Matcher;

use KaririCode\Router\Core\RouteEntry;
use KaririCode\Router\Matcher\RegexMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RegexMatcher::class)]
final class RegexMatcherTest extends TestCase
{
    #[Test]
    public function routesMayShareAParameterName(): void
    {
        $matcher = new RegexMatcher();
        $matcher->add(new RouteEntry(['GET'], '/users/{id:int}', 'users', defaults: ['format' => 'json'], middleware: ['m']));
        $matcher->add(new RouteEntry(['GET'], '/posts/{id}/comments/{comment:int}', 'comments'));

        $users = $matcher->match('get', '/users/42');
        $comments = $matcher->match('GET', '/posts/intro/comments/7');

        self::assertSame('users', $users?->handler());
        self::assertSame(['format' => 'json', 'id' => '42'], $users->parameters->all());
        self::assertSame(['m'], $users->middleware);
        self::assertSame('comments', $comments?->handler());
        self::assertSame(['id' => 'intro', 'comment' => '7'], $comments->parameters->all());
        self::assertNull($matcher->match('GET', '/users/x'));
        self::assertNull($matcher->match('POST', '/users/42'));
    }

    #[Test]
    public function routesBeyondOneChunkStillMatch(): void
    {
        $matcher = new RegexMatcher();

        for ($i = 0; $i < 65; ++$i) {
            $matcher->add(new RouteEntry(['GET'], "/r{$i}/{id:int}/{name}", "h{$i}"));
        }

        self::assertCount(3, $matcher->exportCompiled()['GET']);
        self::assertSame('h0', $matcher->match('GET', '/r0/1/a')?->handler());
        self::assertSame('h29', $matcher->match('GET', '/r29/1/a')?->handler());
        self::assertSame('h30', $matcher->match('GET', '/r30/1/a')?->handler());
        $last = $matcher->match('GET', '/r64/9/z');
        self::assertNotNull($last);
        self::assertSame('h64', $last->handler());
        self::assertSame(['id' => '9', 'name' => 'z'], $last->parameters->all());
    }

    #[Test]
    public function theFirstMatchingRouteWins(): void
    {
        $matcher = new RegexMatcher();
        $matcher->add(new RouteEntry(['GET'], '/users/{id:int}', 'by-id'));
        $matcher->add(new RouteEntry(['GET'], '/users/{name}', 'by-name'));

        self::assertSame('by-id', $matcher->match('GET', '/users/7')?->handler());
        self::assertSame('by-name', $matcher->match('GET', '/users/ana')?->handler());
    }

    #[Test]
    public function staticRoutesAreIgnored(): void
    {
        $matcher = new RegexMatcher();
        $matcher->add(new RouteEntry(['GET'], '/health', 'h'));

        self::assertSame([], $matcher->exportRoutes());
        self::assertNull($matcher->match('GET', '/health'));
    }

    #[Test]
    public function addingARouteRecompiles(): void
    {
        $matcher = new RegexMatcher();
        $matcher->add(new RouteEntry(['GET'], '/a/{x}', 'a'));
        self::assertNull($matcher->match('GET', '/b/1'));

        $matcher->add(new RouteEntry(['GET'], '/b/{x}', 'b'));

        self::assertSame('b', $matcher->match('GET', '/b/1')?->handler());
        self::assertCount(2, $matcher->exportRoutes()['GET']);
    }

    #[Test]
    public function allowedMethodsListsEveryMethodWithAMatchingPattern(): void
    {
        $matcher = new RegexMatcher();
        $matcher->add(new RouteEntry(['GET', 'HEAD'], '/users/{id:int}', 'show'));
        $matcher->add(new RouteEntry(['DELETE'], '/users/{id:int}', 'delete'));
        $matcher->add(new RouteEntry(['POST'], '/posts/{id}', 'post'));

        self::assertSame(['GET', 'HEAD', 'DELETE'], $matcher->allowedMethods('/users/3'));
        self::assertSame([], $matcher->allowedMethods('/users/x'));
    }

    #[Test]
    public function chunksUseBranchResetAndMarks(): void
    {
        $matcher = new RegexMatcher();
        $first = new RouteEntry(['GET'], '/users/{id:int}', 'a');
        $second = new RouteEntry(['GET'], '/p/{x}/{y}', 'b');
        $matcher->add($first);
        $matcher->add($second);

        $chunk = $matcher->exportCompiled()['GET'][0];

        self::assertSame('#\A(?|/users/([0-9]+)(*MARK:0)|/p/([^/\x00-\x1F\x7F]+)/([^/\x00-\x1F\x7F]+)(*MARK:1))\z#u', $chunk['regex']);
        self::assertSame([$first, $second], $chunk['routes']);
        self::assertSame([1, ['a', 'b']], RegexMatcher::locate($chunk['regex'], '/p/a/b'));
        self::assertSame([0, ['5']], RegexMatcher::locate($chunk['regex'], '/users/5'));
        self::assertNull(RegexMatcher::locate($chunk['regex'], '/q'));
        self::assertNull(RegexMatcher::locate('#\A/plain\z#', '/plain'), 'an expression without MARK locates nothing');
    }

    #[Test]
    public function aTrailingNewlineOrInvalidUtf8IsAMiss(): void
    {
        $matcher = new RegexMatcher();
        $matcher->add(new RouteEntry(['GET'], '/users/{id}', 'h'));

        self::assertNull($matcher->match('GET', "/users/1\n"));
        self::assertNull($matcher->match('GET', "/users/\xC3\x28"));
        self::assertSame([], $matcher->allowedMethods("/users/1\n"));
    }
}
