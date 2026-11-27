<?php

declare(strict_types=1);

namespace KaririCode\Router\Integration;

use KaririCode\Router\Core\MatchResult;

/**
 * Bridge to KaririCode\Observability for metrics and tracing.
 *
 * ARFA 1.3 P5 (Continuous Observability): tracks route match latency
 * histogram, match hit/miss counters, 404/405 counters, and middleware
 * execution time.
 *
 * Spec V4.0: final class. Metrics interface duck-typed (increment, histogram).
 *
 * @see https://opentelemetry.io/docs/specs/otel/metrics/ OpenTelemetry metrics conventions
 *
 * Requires: kariricode/observability ^4.0 (optional)
 */
final class ObservabilityBridge
{
    private int $matchCount = 0;
    private int $missCount = 0;
    private int $methodNotAllowedCount = 0;
    private float $totalMatchTimeMs = 0.0;

    /**
     * @param object|null $metrics Any object with increment(string,int):void and histogram(string,float):void
     */
    public function __construct(
        private readonly ?object $metrics = null,
    ) {
    }

    public function recordMatch(MatchResult $result, float $durationMs): void
    {
        ++$this->matchCount;
        $this->totalMatchTimeMs += $durationMs;

        if ($this->metrics !== null && method_exists($this->metrics, 'increment')) {
            $this->metrics->increment('router.match.hit', 1);
        }

        if ($this->metrics !== null && method_exists($this->metrics, 'histogram')) {
            $this->metrics->histogram('router.match.duration_ms', $durationMs);
        }
    }

    public function recordMiss(string $method, string $uri, float $durationMs): void
    {
        ++$this->missCount;
        $this->totalMatchTimeMs += $durationMs;

        if ($this->metrics !== null && method_exists($this->metrics, 'increment')) {
            $this->metrics->increment('router.match.miss', 1);
        }
    }

    public function recordMethodNotAllowed(): void
    {
        ++$this->methodNotAllowedCount;

        if ($this->metrics !== null && method_exists($this->metrics, 'increment')) {
            $this->metrics->increment('router.match.method_not_allowed', 1);
        }
    }

    /**
     * @return array{matches:int,misses:int,method_not_allowed:int,avg_match_ms:float}
     */
    public function snapshot(): array
    {
        $total = $this->matchCount + $this->missCount;

        return [
            'matches' => $this->matchCount,
            'misses' => $this->missCount,
            'method_not_allowed' => $this->methodNotAllowedCount,
            'avg_match_ms' => $total > 0 ? round($this->totalMatchTimeMs / $total, 4) : 0.0,
        ];
    }

    public function reset(): void
    {
        $this->matchCount = 0;
        $this->missCount = 0;
        $this->methodNotAllowedCount = 0;
        $this->totalMatchTimeMs = 0.0;
    }
}
