<?php

declare(strict_types=1);

namespace KaririCode\Router\Core;

use KaririCode\Router\Contract\MatchObserver;
use KaririCode\Router\Exception\MethodNotAllowedException;
use KaririCode\Router\Exception\RouteNotFoundException;

/**
 * Counts matches, 404s and 405s and adds up their durations, in memory: a
 * MatchObserver for tests, benchmarks and long-running workers that report
 * their own figures. It replaces 4.0's ObservabilityBridge, whose metrics
 * object was duck-typed; exporting to a backend is the job of a bridge in
 * that backend's package.
 *
 * Preconditions: the counters belong to this instance; reset() zeroes them.
 *
 * Thread-safety: not synchronised; give each worker its own instance.
 *
 * Side effects: updates its counters on every notification.
 *
 * Example:
 *
 *     $statistics = new MatchStatistics();
 *     $router = new ObservedRouter($registry, $statistics);
 *     // ... requests ...
 *     printf("%d hits, %d misses, %.0f ns on average\n", $statistics->matches, $statistics->misses(), $statistics->averageDurationNs());
 *
 * References: ARFA 1.3 P5; ADR-009; MatchObserver; KaririCode Spec V4.3.3
 * §18 (asymmetric visibility instead of getters).
 *
 * @see https://www.php.net/manual/en/language.oop5.properties.php#language.oop5.properties.asymmetric-property-visibility
 */
final class MatchStatistics implements MatchObserver
{
    public private(set) int $matches = 0;

    public private(set) int $notFound = 0;

    public private(set) int $methodNotAllowed = 0;

    public private(set) int $totalDurationNs = 0;

    #[\Override]
    public function routeMatched(MatchResult $result, int $durationNs): void
    {
        ++$this->matches;
        $this->totalDurationNs += $durationNs;
    }

    #[\Override]
    public function routeMissed(string $method, RouteNotFoundException|MethodNotAllowedException $failure, int $durationNs): void
    {
        if ($failure instanceof MethodNotAllowedException) {
            ++$this->methodNotAllowed;
        } else {
            ++$this->notFound;
        }

        $this->totalDurationNs += $durationNs;
    }

    /** 404s and 405s together. */
    public function misses(): int
    {
        return $this->notFound + $this->methodNotAllowed;
    }

    /** Mean duration of every observed match, hit or miss; 0.0 before the first. */
    public function averageDurationNs(): float
    {
        $total = $this->matches + $this->misses();

        return $total === 0 ? 0.0 : $this->totalDurationNs / $total;
    }

    public function reset(): void
    {
        $this->matches = 0;
        $this->notFound = 0;
        $this->methodNotAllowed = 0;
        $this->totalDurationNs = 0;
    }
}
