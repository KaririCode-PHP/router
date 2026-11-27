<?php

declare(strict_types=1);

namespace KaririCode\Router\Tests\Unit\Middleware;

use KaririCode\Router\Contract\Middleware;
use KaririCode\Router\Middleware\MiddlewarePipeline;
use PHPUnit\Framework\TestCase;

final class MiddlewarePipelineTest extends TestCase
{
    public function testEmptyPipelineCallsHandler(): void
    {
        $pipeline = new MiddlewarePipeline();
        $result = $pipeline->execute(
            [],
            fn (array $ctx): string => 'handled:' . $ctx['value'],
            ['value' => 'test'],
        );

        self::assertSame('handled:test', $result);
    }

    public function testMiddlewareExecutedInOrder(): void
    {
        $log = [];

        $mw1 = new class ($log) implements Middleware {
            public function __construct(private array &$log) {}
            public function handle(array $context, callable $next): mixed
            {
                $this->log[] = 'before:1';
                $result = $next($context);
                $this->log[] = 'after:1';
                return $result;
            }
        };

        $mw2 = new class ($log) implements Middleware {
            public function __construct(private array &$log) {}
            public function handle(array $context, callable $next): mixed
            {
                $this->log[] = 'before:2';
                $result = $next($context);
                $this->log[] = 'after:2';
                return $result;
            }
        };

        $pipeline = new MiddlewarePipeline();
        $pipeline->execute(
            [$mw1, $mw2],
            fn (array $ctx): string => 'done',
            [],
        );

        self::assertSame(['before:1', 'before:2', 'after:2', 'after:1'], $log);
    }

    public function testMiddlewareCanModifyContext(): void
    {
        $mw = new class implements Middleware {
            public function handle(array $context, callable $next): mixed
            {
                $context['enriched'] = true;
                return $next($context);
            }
        };

        $pipeline = new MiddlewarePipeline();
        $result = $pipeline->execute(
            [$mw],
            fn (array $ctx): bool => $ctx['enriched'] ?? false,
            [],
        );

        self::assertTrue($result);
    }

    public function testMiddlewareCanShortCircuit(): void
    {
        $mw = new class implements Middleware {
            public function handle(array $context, callable $next): mixed
            {
                return 'blocked';
            }
        };

        $pipeline = new MiddlewarePipeline();
        $result = $pipeline->execute(
            [$mw],
            fn (array $ctx): string => 'should-not-reach',
            [],
        );

        self::assertSame('blocked', $result);
    }
}
