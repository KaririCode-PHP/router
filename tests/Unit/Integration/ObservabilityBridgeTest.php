<?php

declare(strict_types=1);

namespace KaririCode\Router\Tests\Unit\Integration;

use KaririCode\Router\Core\MatchResult;
use KaririCode\Router\Core\RouteEntry;
use KaririCode\Router\Integration\ObservabilityBridge;
use PHPUnit\Framework\TestCase;

final class ObservabilityBridgeTest extends TestCase
{
    private function createResult(): MatchResult
    {
        return new MatchResult(
            new RouteEntry(['GET'], '/test', [self::class, 'setUp']),
        );
    }

    public function testInitialSnapshotAllZeros(): void
    {
        $bridge = new ObservabilityBridge();
        $snap = $bridge->snapshot();

        self::assertSame(0, $snap['matches']);
        self::assertSame(0, $snap['misses']);
        self::assertSame(0, $snap['method_not_allowed']);
        self::assertSame(0.0, $snap['avg_match_ms']);
    }

    public function testRecordMatchIncrementsCounter(): void
    {
        $bridge = new ObservabilityBridge();
        $bridge->recordMatch($this->createResult(), 1.5);
        $bridge->recordMatch($this->createResult(), 2.5);

        $snap = $bridge->snapshot();
        self::assertSame(2, $snap['matches']);
        self::assertSame(2.0, $snap['avg_match_ms']);
    }

    public function testRecordMissIncrementsCounter(): void
    {
        $bridge = new ObservabilityBridge();
        $bridge->recordMiss('GET', '/404', 0.8);

        self::assertSame(1, $bridge->snapshot()['misses']);
    }

    public function testRecordMethodNotAllowed(): void
    {
        $bridge = new ObservabilityBridge();
        $bridge->recordMethodNotAllowed();
        $bridge->recordMethodNotAllowed();

        self::assertSame(2, $bridge->snapshot()['method_not_allowed']);
    }

    public function testReset(): void
    {
        $bridge = new ObservabilityBridge();
        $bridge->recordMatch($this->createResult(), 1.0);
        $bridge->recordMiss('GET', '/x', 2.0);
        $bridge->recordMethodNotAllowed();
        $bridge->reset();

        $snap = $bridge->snapshot();
        self::assertSame(0, $snap['matches']);
        self::assertSame(0, $snap['misses']);
        self::assertSame(0, $snap['method_not_allowed']);
    }

    public function testMetricsIntegration(): void
    {
        $metrics = new class {
            public array $increments = [];
            public array $histograms = [];
            public function increment(string $key, int $val): void {
                $this->increments[$key] = ($this->increments[$key] ?? 0) + $val;
            }
            public function histogram(string $key, float $val): void {
                $this->histograms[$key][] = $val;
            }
        };

        $bridge = new ObservabilityBridge($metrics);
        $bridge->recordMatch($this->createResult(), 1.23);
        $bridge->recordMiss('GET', '/404', 0.5);
        $bridge->recordMethodNotAllowed();

        self::assertSame(1, $metrics->increments['router.match.hit']);
        self::assertSame(1, $metrics->increments['router.match.miss']);
        self::assertSame(1, $metrics->increments['router.match.method_not_allowed']);
        self::assertSame([1.23], $metrics->histograms['router.match.duration_ms']);
    }

    public function testNullMetricsDoesNotThrow(): void
    {
        $bridge = new ObservabilityBridge(null);
        $bridge->recordMatch($this->createResult(), 1.0);
        $bridge->recordMiss('GET', '/x', 2.0);
        $bridge->recordMethodNotAllowed();

        self::assertSame(1, $bridge->snapshot()['matches']);
    }
}
