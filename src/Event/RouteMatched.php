<?php

declare(strict_types=1);

namespace KaririCode\Router\Event;

use KaririCode\Router\Core\MatchResult;

/**
 * A router matched a request: what EventDispatcherBridge dispatches for
 * each successful ObservedRouter::match(), for listeners that record
 * latency per route or audit which handler ran.
 *
 * Preconditions: $durationNs comes from the monotonic hrtime() clock. The
 * result's parameters are path data and may be personal data; a listener
 * that exports them redacts first (Spec V4.3.3 §23.7).
 *
 * Thread-safety: immutable.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     $listeners->listen(RouteMatched::class, static function (RouteMatched $event) use ($histogram): void {
 *         $histogram->observe($event->durationNs / 1e6, ['route' => $event->result->routeName() ?? 'unnamed']);
 *     });
 *
 * References: PSR-14; ARFA 1.3 P5; KaririCode Spec V4.3.3 §16.3 (events
 * are past participles).
 *
 * @see https://www.php-fig.org/psr/psr-14/
 */
final readonly class RouteMatched
{
    public function __construct(
        public MatchResult $result,
        public int $durationNs,
    ) {}
}
