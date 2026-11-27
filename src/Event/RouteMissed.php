<?php

declare(strict_types=1);

namespace KaririCode\Router\Event;

use KaririCode\Router\Exception\MethodNotAllowedException;
use KaririCode\Router\Exception\RouteNotFoundException;

/**
 * A router found no route for a request: what EventDispatcherBridge
 * dispatches when ObservedRouter::match() is about to throw. The failure
 * tells a 404 (RouteNotFoundException) from a 405
 * (MethodNotAllowedException, with the accepted methods).
 *
 * Preconditions: $method is the method as the caller passed it, not
 * validated; the path is left out on purpose (Spec V4.3.3 §23.7). The
 * exception is still thrown to the caller after the event.
 *
 * Thread-safety: immutable.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     $listeners->listen(RouteMissed::class, static function (RouteMissed $event) use ($counter): void {
 *         $counter->increment($event->failure instanceof MethodNotAllowedException ? 'router.405' : 'router.404');
 *     });
 *
 * References: PSR-14; ARFA 1.3 P5; RFC 9110 §15.5.5 and §15.5.6.
 *
 * @see https://www.php-fig.org/psr/psr-14/
 */
final readonly class RouteMissed
{
    public function __construct(
        public string $method,
        public RouteNotFoundException|MethodNotAllowedException $failure,
        public int $durationNs,
    ) {}
}
