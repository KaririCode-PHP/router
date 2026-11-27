<?php

declare(strict_types=1);

namespace KaririCode\Router\Tests\Unit\Core;

use KaririCode\Router\Core\NormalizedRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NormalizedRequest::class)]
final class NormalizedRequestTest extends TestCase
{
    #[Test]
    public function itCarriesTheMethodThePathAndTheNativeRequest(): void
    {
        $native = new \stdClass();
        $request = new NormalizedRequest('SUBSCRIBE', '/rooms/42', $native);

        self::assertSame('SUBSCRIBE', $request->method);
        self::assertSame('/rooms/42', $request->uri);
        self::assertSame($native, $request->original);
        self::assertNull((new NormalizedRequest('RUN', '/cache:clear'))->original);
    }
}
