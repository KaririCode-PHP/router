<?php

declare(strict_types=1);

namespace KaririCode\Router\Contract;

use KaririCode\Router\Core\MatchResult;
use KaririCode\Router\Exception\MethodNotAllowedException;
use KaririCode\Router\Exception\RouteNotFoundException;

/**
 * Hears the outcome and the duration of every match an ObservedRouter
 * performs: the metrics half of ARFA 1.3 P5. The interface is
 * vendor-neutral; EventDispatcherBridge turns it into PSR-14 events,
 * MatchStatistics counts in memory, and a bridge to an observability
 * backend lives in that backend's package.
 *
 * Preconditions: exactly one method is called per match. Durations come
 * from the monotonic hrtime() clock, in nanoseconds. The path is not
 * passed: it is client data that may carry personal data, and a backend
 * that wants it can take it at the edge, where redaction rules live
 * (Spec V4.3.3 §23.7). The method is passed as the caller gave it, so a
 * metrics bridge should bound its label values. Implementations must not
 * throw: an exception propagates out of ObservedRouter::match().
 *
 * Thread-safety: called on the matching thread; an implementation that
 * keeps counters owns their synchronisation.
 *
 * Side effects: whatever the implementation records.
 *
 * Example:
 *
 *     $router = new ObservedRouter($registry, $statistics = new MatchStatistics());
 *     $router->match('GET', '/health');
 *     $statistics->matches; // 1
 *
 * References: ARFA 1.3 P5; KaririCode Spec V4.3.3 §23.7 (PII in
 * telemetry); ADR-009; kariricode/processor-pipeline PipelineObserver, the
 * same pattern.
 *
 * @see https://www.php.net/manual/en/function.hrtime.php
 */
interface MatchObserver
{
    public function routeMatched(MatchResult $result, int $durationNs): void;

    public function routeMissed(string $method, RouteNotFoundException|MethodNotAllowedException $failure, int $durationNs): void;
}
