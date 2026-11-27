<?php

declare(strict_types=1);

namespace KaririCode\Router\Tests\Unit\Enum;

use KaririCode\Router\Enum\RouteParameterType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RouteParameterType::class)]
final class RouteParameterTypeTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?RouteParameterType}>
     */
    public static function names(): iterable
    {
        yield 'int' => ['int', RouteParameterType::Int];
        yield 'integer alias' => ['integer', RouteParameterType::Int];
        yield 'upper case' => ['INT', RouteParameterType::Int];
        yield 'string' => ['string', RouteParameterType::String];
        yield 'slug' => ['Slug', RouteParameterType::Slug];
        yield 'uuid' => ['uuid', RouteParameterType::Uuid];
        yield 'any' => ['any', RouteParameterType::Any];
        yield 'unknown' => ['date', null];
        yield 'padded' => [' int', null];
        yield 'empty' => ['', null];
    }

    #[Test]
    #[DataProvider('names')]
    public function tryFromStringKnowsTheFiveTypes(string $name, ?RouteParameterType $expected): void
    {
        self::assertSame($expected, RouteParameterType::tryFromString($name));
    }

    /**
     * @return iterable<string, array{RouteParameterType, string, list<string>, list<string>}>
     */
    public static function fragments(): iterable
    {
        yield 'int' => [RouteParameterType::Int, '[0-9]+', ['0', '42'], ['-1', '4.2', 'a']];
        yield 'string' => [RouteParameterType::String, '[^/\x00-\x1F\x7F]+', ['ana', 'a b', 'ção'], ['a/b', '', "a\nb", "\x7F"]];
        yield 'slug' => [RouteParameterType::Slug, '[a-z0-9]+(?:-[a-z0-9]+)*', ['hello-world', 'a1'], ['-a', 'a--b', 'A']];
        yield 'uuid' => [
            RouteParameterType::Uuid,
            '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}',
            ['550e8400-e29b-41d4-a716-446655440000', '550E8400-E29B-41D4-A716-446655440000'],
            ['550e8400e29b41d4a716446655440000', '550e8400-e29b-41d4-a716-44665544000g'],
        ];
        yield 'any' => [RouteParameterType::Any, '[^\x00-\x1F\x7F]+', ['a/b/c', 'x'], ['', "a/\tb", "\x00"]];
    }

    /**
     * @param list<string> $accepted
     * @param list<string> $rejected
     */
    #[Test]
    #[DataProvider('fragments')]
    public function eachTypeCompilesToAFragmentWithoutCapturingGroups(RouteParameterType $type, string $fragment, array $accepted, array $rejected): void
    {
        self::assertSame($fragment, $type->pattern());

        foreach ($accepted as $value) {
            self::assertSame(1, preg_match('#\A(?:' . $type->pattern() . ')\z#u', $value), $value);
        }

        foreach ($rejected as $value) {
            self::assertSame(0, preg_match('#\A(?:' . $type->pattern() . ')\z#u', $value), $value);
        }

        self::assertSame(1, preg_match('#\A(' . $type->pattern() . ')\z#u', $accepted[0], $groups));
        self::assertCount(2, $groups, 'no capturing group of its own');
    }
}
