<?php

declare(strict_types=1);

namespace KaririCode\Router\Tests\Unit\Generator;

use KaririCode\Router\Core\RouteRegistry;
use KaririCode\Router\Exception\RouteNotFoundException;
use KaririCode\Router\Exception\RouterCode;
use KaririCode\Router\Exception\UrlGenerationException;
use KaririCode\Router\Generator\RouteUrlGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RouteUrlGenerator::class)]
final class RouteUrlGeneratorTest extends TestCase
{
    private RouteUrlGenerator $generator;

    protected function setUp(): void
    {
        $router = (new RouteRegistry())
            ->get('/users', 'index', 'users.index')
            ->get('/users/{id:int}', 'show', 'users.show')
            ->get('/posts/{year:int}/{slug:slug}', 'post', 'posts.show')
            ->get('/files/{path:any}', 'file', 'files.show')
            ->get('/people/{name}', 'person', 'people.show')
            ->route(['GET'], '/reports/{format}', 'report', 'reports', defaults: ['format' => 'pdf']);

        $this->generator = new RouteUrlGenerator($router);
    }

    #[Test]
    public function itFillsThePlaceholders(): void
    {
        self::assertSame('/users', $this->generator->generate('users.index'));
        self::assertSame('/users/42', $this->generator->generate('users.show', ['id' => 42]));
        self::assertSame('/posts/2026/hello-world', $this->generator->generate('posts.show', ['year' => '2026', 'slug' => 'hello-world']));
    }

    #[Test]
    public function aRouteDefaultFillsAMissingParameter(): void
    {
        self::assertSame('/reports/pdf', $this->generator->generate('reports'));
        self::assertSame('/reports/csv', $this->generator->generate('reports', ['format' => 'csv']));
    }

    #[Test]
    public function extraParametersAndTheQueryJoinTheQueryString(): void
    {
        self::assertSame('/users/42?tab=posts', $this->generator->generate('users.show', ['id' => 42, 'tab' => 'posts']));
        self::assertSame('/users?page=2&q=a%20b', $this->generator->generate('users.index', ['page' => 2], ['q' => 'a b']));
        self::assertSame('/users?page=3', $this->generator->generate('users.index', ['page' => 2], ['page' => '3']));
    }

    #[Test]
    public function valuesArePercentEncodedPerSegment(): void
    {
        self::assertSame('/people/Jo%C3%A3o%20Silva', $this->generator->generate('people.show', ['name' => 'João Silva']));
        self::assertSame('/files/docs/2026/report%20final.pdf', $this->generator->generate('files.show', ['path' => 'docs/2026/report final.pdf']));
    }

    #[Test]
    public function withBasePrependsTheBaseWithoutItsTrailingSlash(): void
    {
        $based = $this->generator->withBase('https://api.example.com/');

        self::assertSame('https://api.example.com/users/1', $based->generate('users.show', ['id' => 1]));
        self::assertSame('/users/1', $this->generator->generate('users.show', ['id' => 1]));
    }

    #[Test]
    public function anUnknownNameIsNotFound(): void
    {
        $this->expectException(RouteNotFoundException::class);
        $this->expectExceptionCode(RouterCode::UnknownRouteName->value);
        $this->expectExceptionMessage('No route is named "users.edit".');

        $this->generator->generate('users.edit');
    }

    #[Test]
    public function aMissingParameterFails(): void
    {
        $this->expectException(UrlGenerationException::class);
        $this->expectExceptionCode(RouterCode::MissingParameter->value);
        $this->expectExceptionMessage('Route "posts.show" needs a value for the parameter "slug".');

        $this->generator->generate('posts.show', ['year' => 2026]);
    }

    #[Test]
    public function aValueThatBreaksItsTypeFailsWithoutEchoingIt(): void
    {
        try {
            $this->generator->generate('users.show', ['id' => 'secret-value']);
            self::fail('UrlGenerationException expected');
        } catch (UrlGenerationException $e) {
            self::assertSame(RouterCode::ConstraintViolation, $e->errorCode);
            self::assertStringNotContainsString('secret-value', $e->getMessage());
        }

        $this->expectException(UrlGenerationException::class);
        $this->generator->generate('people.show', ['name' => 'a/b']);
    }
}
