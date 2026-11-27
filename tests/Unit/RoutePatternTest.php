<?php

declare(strict_types=1);

namespace KaririCode\Router\Tests\Unit;

use KaririCode\Router\Enum\RouteParameterType;
use KaririCode\Router\ValueObject\RoutePattern;
use PHPUnit\Framework\TestCase;

final class RoutePatternTest extends TestCase
{
    // ── Static Patterns ──────────────────────────────────────────────────

    public function testStaticPatternMatchesExactly(): void
    {
        $pattern = new RoutePattern('/users');
        self::assertTrue($pattern->isStatic);
        self::assertSame([], $pattern->matchAgainst('/users'));
        self::assertNull($pattern->matchAgainst('/users/1'));
    }

    public function testStaticPatternWithTrailingSlash(): void
    {
        $pattern = new RoutePattern('/api/v1/');
        self::assertSame([], $pattern->matchAgainst('/api/v1/'));
        self::assertNull($pattern->matchAgainst('/api/v1'));
    }

    // ── Parameterized Patterns ───────────────────────────────────────────

    public function testSingleParameter(): void
    {
        $pattern = new RoutePattern('/users/{id}');
        self::assertFalse($pattern->isStatic);
        self::assertSame(['id' => '42'], $pattern->matchAgainst('/users/42'));
        self::assertSame(['id' => 'abc'], $pattern->matchAgainst('/users/abc'));
        self::assertNull($pattern->matchAgainst('/users'));
        self::assertNull($pattern->matchAgainst('/users/'));
    }

    public function testMultipleParameters(): void
    {
        $pattern = new RoutePattern('/posts/{year}/{slug}');
        $result = $pattern->matchAgainst('/posts/2025/hello-world');
        self::assertSame(['year' => '2025', 'slug' => 'hello-world'], $result);
    }

    // ── Typed Parameters ─────────────────────────────────────────────────

    public function testIntConstraint(): void
    {
        $pattern = new RoutePattern('/users/{id:int}');
        self::assertSame(['id' => '42'], $pattern->matchAgainst('/users/42'));
        self::assertNull($pattern->matchAgainst('/users/abc'));
        self::assertSame(RouteParameterType::Int, $pattern->parameterTypes['id']);
    }

    public function testSlugConstraint(): void
    {
        $pattern = new RoutePattern('/posts/{slug:slug}');
        self::assertSame(['slug' => 'hello-world'], $pattern->matchAgainst('/posts/hello-world'));
        self::assertNull($pattern->matchAgainst('/posts/Hello World'));
    }

    public function testUuidConstraint(): void
    {
        $pattern = new RoutePattern('/items/{uuid:uuid}');
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        self::assertSame(['uuid' => $uuid], $pattern->matchAgainst("/items/{$uuid}"));
        self::assertNull($pattern->matchAgainst('/items/not-a-uuid'));
    }

    public function testAnyConstraintIsGreedy(): void
    {
        $pattern = new RoutePattern('/files/{path:any}');
        self::assertSame(
            ['path' => 'docs/2025/report.pdf'],
            $pattern->matchAgainst('/files/docs/2025/report.pdf'),
        );
    }

    // ── Reverse URI ──────────────────────────────────────────────────────

    public function testReverseUri(): void
    {
        $pattern = new RoutePattern('/users/{id:int}/posts/{slug:slug}');
        $uri = $pattern->reverseUri(['id' => '42', 'slug' => 'hello']);
        self::assertSame('/users/42/posts/hello', $uri);
    }

    public function testReverseUriThrowsOnMissingParam(): void
    {
        $pattern = new RoutePattern('/users/{id}');
        $this->expectException(\InvalidArgumentException::class);
        $pattern->reverseUri([]);
    }

    // ── Parameter Names ──────────────────────────────────────────────────

    public function testParameterNamesExtracted(): void
    {
        $pattern = new RoutePattern('/a/{x}/b/{y:int}/c/{z:uuid}');
        self::assertSame(['x', 'y', 'z'], $pattern->parameterNames);
    }
}
