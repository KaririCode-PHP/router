<?php

declare(strict_types=1);

namespace KaririCode\Router\Tests\Unit\ValueObject;

use KaririCode\Router\ValueObject\RouteParameters;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RouteParameters::class)]
final class RouteParametersTest extends TestCase
{
    #[Test]
    public function itReadsParametersWithDefaults(): void
    {
        $parameters = new RouteParameters(['id' => '42', 'slug' => 'intro']);

        self::assertSame('42', $parameters->get('id'));
        self::assertNull($parameters->get('missing'));
        self::assertSame('x', $parameters->get('missing', 'x'));
        self::assertTrue($parameters->has('slug'));
        self::assertFalse($parameters->has('missing'));
        self::assertSame(['id' => '42', 'slug' => 'intro'], $parameters->all());
        self::assertCount(2, $parameters);
        self::assertCount(0, new RouteParameters());
    }

    #[Test]
    public function getIntConvertsOnlyDecimalIntegers(): void
    {
        $parameters = new RouteParameters(['id' => '42', 'zero' => '0', 'text' => '42abc', 'huge' => '99999999999999999999', 'negative' => '-3']);

        self::assertSame(42, $parameters->getInt('id'));
        self::assertSame(0, $parameters->getInt('zero', 7));
        self::assertSame(-3, $parameters->getInt('negative'));
        self::assertNull($parameters->getInt('text'));
        self::assertSame(7, $parameters->getInt('text', 7));
        self::assertSame(7, $parameters->getInt('huge', 7));
        self::assertSame(9, $parameters->getInt('missing', 9));
        self::assertNull($parameters->getInt('missing'));
    }

    #[Test]
    public function mergeReturnsACopyWhereTheExtraWins(): void
    {
        $parameters = new RouteParameters(['id' => '1', 'format' => 'json']);
        $merged = $parameters->merge(['format' => 'xml', 'page' => '2']);

        self::assertSame(['id' => '1', 'format' => 'xml', 'page' => '2'], $merged->all());
        self::assertSame(['id' => '1', 'format' => 'json'], $parameters->all());
    }
}
