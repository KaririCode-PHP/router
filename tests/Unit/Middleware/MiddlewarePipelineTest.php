<?php

declare(strict_types=1);

namespace KaririCode\Router\Tests\Unit\Middleware;

use KaririCode\Router\Middleware\MiddlewarePipeline;
use KaririCode\Router\Tests\Fixture\TraceMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MiddlewarePipeline::class)]
final class MiddlewarePipelineTest extends TestCase
{
    #[Test]
    public function theFirstMiddlewareIsTheOutermostLayer(): void
    {
        $result = (new MiddlewarePipeline())->execute(
            [new TraceMiddleware('a'), new TraceMiddleware('b'), new TraceMiddleware('c')],
            static fn(array $context): string => (is_string($context['trace'] ?? null) ? $context['trace'] : '') . 'handler',
            ['trace' => ''],
        );

        self::assertSame('a>b>c>handler<c<b<a', $result);
    }

    #[Test]
    public function aLayerCanShortCircuitTheRest(): void
    {
        $reached = false;
        $result = (new MiddlewarePipeline())->execute(
            [new TraceMiddleware('a'), new TraceMiddleware('stop', shortCircuit: true), new TraceMiddleware('c')],
            static function () use (&$reached): string {
                $reached = true;

                return 'handler';
            },
            [],
        );

        self::assertSame('a>stop!<a', $result);
        self::assertFalse($reached);
    }

    #[Test]
    public function withoutMiddlewareTheHandlerGetsTheContext(): void
    {
        self::assertSame(['id' => 7], (new MiddlewarePipeline())->execute([], static fn(array $context): array => $context, ['id' => 7]));
    }
}
