<?php

declare(strict_types=1);

namespace KaririCode\Router\Tests\Unit\Generator;

use KaririCode\Router\Core\RouteRegistry;
use KaririCode\Router\Exception\UrlGenerationException;
use KaririCode\Router\Generator\RouteUrlGenerator;
use PHPUnit\Framework\TestCase;

final class RouteUrlGeneratorTest extends TestCase
{
    private RouteRegistry $registry;
    private RouteUrlGenerator $generator;

    protected function setUp(): void
    {
        $this->registry = new RouteRegistry();
        $this->generator = new RouteUrlGenerator($this->registry);
    }

    public function testGenerateStaticRoute(): void
    {
        $this->registry->get('/users', 'handler', 'users.index');
        self::assertSame('/users', $this->generator->generate('users.index'));
    }

    public function testGenerateWithParameter(): void
    {
        $this->registry->get('/users/{id:int}', 'handler', 'users.show');
        self::assertSame('/users/42', $this->generator->generate('users.show', ['id' => 42]));
    }

    public function testGenerateWithMultipleParameters(): void
    {
        $this->registry->get('/posts/{year:int}/{slug:slug}', 'handler', 'posts.show');
        $uri = $this->generator->generate('posts.show', ['year' => 2025, 'slug' => 'hello']);
        self::assertSame('/posts/2025/hello', $uri);
    }

    public function testGenerateWithQueryString(): void
    {
        $this->registry->get('/users', 'handler', 'users.index');
        $uri = $this->generator->generate('users.index', [], ['page' => '2', 'sort' => 'name']);
        self::assertSame('/users?page=2&sort=name', $uri);
    }

    public function testUnusedParametersAppendedAsQuery(): void
    {
        $this->registry->get('/users/{id:int}', 'handler', 'users.show');
        $uri = $this->generator->generate('users.show', ['id' => 1, 'tab' => 'posts']);
        self::assertSame('/users/1?tab=posts', $uri);
    }

    public function testWithBaseUri(): void
    {
        $this->registry->get('/users', 'handler', 'users.index');
        $gen = $this->generator->withBase('https://api.example.com');
        self::assertSame('https://api.example.com/users', $gen->generate('users.index'));
    }

    public function testUnknownRouteThrows(): void
    {
        $this->expectException(UrlGenerationException::class);
        $this->generator->generate('nonexistent');
    }

    public function testMissingParameterThrows(): void
    {
        $this->registry->get('/users/{id:int}', 'handler', 'users.show');
        $this->expectException(UrlGenerationException::class);
        $this->generator->generate('users.show');
    }

    public function testConstraintViolationThrows(): void
    {
        $this->registry->get('/users/{id:int}', 'handler', 'users.show');
        $this->expectException(UrlGenerationException::class);
        $this->generator->generate('users.show', ['id' => 'abc']);
    }
}
