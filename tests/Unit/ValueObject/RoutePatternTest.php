<?php

declare(strict_types=1);

namespace KaririCode\Router\Tests\Unit\ValueObject;

use KaririCode\Router\Enum\RouteParameterType;
use KaririCode\Router\Exception\RouteCompilationException;
use KaririCode\Router\Exception\RouterCode;
use KaririCode\Router\ValueObject\RoutePattern;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RoutePattern::class)]
final class RoutePatternTest extends TestCase
{
    #[Test]
    public function aPatternWithoutPlaceholdersIsStatic(): void
    {
        $pattern = new RoutePattern('/health.json');

        self::assertTrue($pattern->isStatic);
        self::assertSame([], $pattern->parameterNames);
        self::assertSame([], $pattern->parameterTypes);
        self::assertSame('#\A/health\.json\z#u', $pattern->regex);
        self::assertSame('/health\.json', $pattern->fragment);
        self::assertSame([], $pattern->matchAgainst('/health.json'));
        self::assertNull($pattern->matchAgainst('/healthXjson'));
    }

    #[Test]
    public function placeholdersCompileToNamedAndPositionalGroups(): void
    {
        $pattern = new RoutePattern('/posts/{year:int}/{slug:slug}');

        self::assertFalse($pattern->isStatic);
        self::assertSame(['year', 'slug'], $pattern->parameterNames);
        self::assertSame(['year' => RouteParameterType::Int, 'slug' => RouteParameterType::Slug], $pattern->parameterTypes);
        self::assertSame('#\A/posts/(?P<year>[0-9]+)/(?P<slug>[a-z0-9]+(?:-[a-z0-9]+)*)\z#u', $pattern->regex);
        self::assertSame('/posts/([0-9]+)/([a-z0-9]+(?:-[a-z0-9]+)*)', $pattern->fragment);
        self::assertSame('/posts/{year:int}/{slug:slug}', $pattern->raw);
    }

    #[Test]
    public function anUntypedPlaceholderIsAString(): void
    {
        $pattern = new RoutePattern('/users/{name}');

        self::assertSame(['name' => RouteParameterType::String], $pattern->parameterTypes);
        self::assertSame(['name' => 'ana'], $pattern->matchAgainst('/users/ana'));
        self::assertNull($pattern->matchAgainst('/users/ana/posts'));
    }

    #[Test]
    public function matchingReturnsTheParametersInPatternOrder(): void
    {
        $pattern = new RoutePattern('/posts/{year:int}/{slug:slug}');

        self::assertSame(['year' => '2026', 'slug' => 'hello-world'], $pattern->matchAgainst('/posts/2026/hello-world'));
        self::assertNull($pattern->matchAgainst('/posts/26a/hello'));
        self::assertNull($pattern->matchAgainst('/posts/2026/Hello'));
    }

    #[Test]
    public function aTrailingNewlineIsNotAMatch(): void
    {
        self::assertNull((new RoutePattern('/users/{id:int}'))->matchAgainst("/users/42\n"));
        self::assertNull((new RoutePattern('/users'))->matchAgainst("/users\n"));
    }

    #[Test]
    public function literalTextIsEscaped(): void
    {
        $pattern = new RoutePattern('/a.b+c#d/{x}');

        self::assertSame(['x' => 'y'], $pattern->matchAgainst('/a.b+c#d/y'));
        self::assertNull($pattern->matchAgainst('/aXbbc#d/y'));
    }

    #[Test]
    public function anyCrossesSlashesAndUuidAcceptsBothCases(): void
    {
        self::assertSame(['path' => 'a/b/c.txt'], (new RoutePattern('/files/{path:any}'))->matchAgainst('/files/a/b/c.txt'));
        self::assertSame(
            ['id' => '550E8400-e29b-41d4-A716-446655440000'],
            (new RoutePattern('/items/{id:uuid}'))->matchAgainst('/items/550E8400-e29b-41d4-A716-446655440000'),
        );
    }

    #[Test]
    public function bindPairsCapturesWithNames(): void
    {
        $pattern = new RoutePattern('/{a}/{b}');

        self::assertSame(['a' => 'x', 'b' => 'y'], $pattern->bind(['x', 'y']));
        self::assertSame(['a' => 'x', 'b' => ''], $pattern->bind(['x']));
    }

    #[Test]
    public function aPlaceholderNameMayStartWithAnUnderscore(): void
    {
        self::assertSame(['_locale' => 'pt'], (new RoutePattern('/{_locale}'))->matchAgainst('/pt'));
    }

    /**
     * @return iterable<string, array{string, RouterCode, string}>
     */
    public static function invalidPatterns(): iterable
    {
        yield 'unclosed placeholder' => ['/users/{id', RouterCode::InvalidPattern, 'a brace is not part of'];
        yield 'stray closing brace' => ['/users/id}', RouterCode::InvalidPattern, 'a brace is not part of'];
        yield 'nested braces' => ['/x/{id:\d{3}}', RouterCode::InvalidPattern, 'a brace is not part of'];
        yield 'name starts with a digit' => ['/x/{1st}', RouterCode::InvalidPattern, 'a placeholder must read'];
        yield 'name with a hyphen' => ['/x/{user-id}', RouterCode::InvalidPattern, 'a placeholder must read'];
        yield 'empty placeholder' => ['/x/{}', RouterCode::InvalidPattern, 'a placeholder must read'];
        yield 'duplicate name' => ['/x/{id}/y/{id:int}', RouterCode::DuplicateParameter, 'names the parameter "id" twice'];
        yield 'unknown type' => ['/x/{id:integr}', RouterCode::UnknownParameterType, '"integr", which is not one of'];
        yield 'raw regex' => ['/x/{id:\d+}', RouterCode::UnknownParameterType, 'which is not one of'];
        yield 'empty type' => ['/x/{id:}', RouterCode::UnknownParameterType, 'uses "", which'];
    }

    #[Test]
    #[DataProvider('invalidPatterns')]
    public function anInvalidPatternFailsWhenTheRouteIsDefined(string $raw, RouterCode $code, string $message): void
    {
        try {
            new RoutePattern($raw);
            self::fail('RouteCompilationException expected');
        } catch (RouteCompilationException $e) {
            self::assertSame($code, $e->errorCode);
            self::assertStringContainsString($message, $e->getMessage());
            self::assertStringContainsString(json_encode($raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $e->getMessage());
        }
    }
}
