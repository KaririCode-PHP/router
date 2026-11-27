<?php

declare(strict_types=1);

namespace KaririCode\Router\Tests\Unit\Enum;

use KaririCode\Router\Enum\HttpMethod;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @psalm-suppress DeprecatedClass
 */
#[CoversClass(HttpMethod::class)]
final class HttpMethodTest extends TestCase
{
    #[Test]
    public function casesAreTheUppercaseMethodNames(): void
    {
        self::assertSame(
            ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'],
            array_map(static fn(HttpMethod $method): string => $method->value, HttpMethod::cases()),
        );
    }

    #[Test]
    public function safeAndIdempotentFollowRfc9110(): void
    {
        $safe = array_values(array_filter(HttpMethod::cases(), static fn(HttpMethod $method): bool => $method->isSafe()));
        $idempotent = array_values(array_filter(HttpMethod::cases(), static fn(HttpMethod $method): bool => $method->isIdempotent()));

        self::assertSame([HttpMethod::Get, HttpMethod::Head, HttpMethod::Options], $safe);
        self::assertSame([HttpMethod::Get, HttpMethod::Put, HttpMethod::Delete, HttpMethod::Head, HttpMethod::Options], $idempotent);
    }
}
