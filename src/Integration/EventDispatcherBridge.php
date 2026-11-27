<?php

declare(strict_types=1);

namespace KaririCode\Router\Integration;

use KaririCode\Router\Contract\MatchObserver;
use KaririCode\Router\Core\MatchResult;
use KaririCode\Router\Event\RouteMatched;
use KaririCode\Router\Event\RouteMissed;
use KaririCode\Router\Exception\MethodNotAllowedException;
use KaririCode\Router\Exception\RouteNotFoundException;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Publishes match outcomes as PSR-14 events: a MatchObserver that turns
 * each notification of ObservedRouter into RouteMatched or RouteMissed. It
 * replaces 4.0's bridge, which typed the dispatcher as `object` and sent
 * stdClass events no listener could type-hint (code review ARCH-006).
 *
 * Preconditions: install a psr/event-dispatcher implementation; this class
 * is the only one that needs it. Listeners must not throw (MatchObserver).
 *
 * Thread-safety: keeps no state; the dispatcher owns its listeners.
 *
 * Side effects: dispatches one event per match.
 *
 * Example:
 *
 *     $router = new ObservedRouter($registry, new EventDispatcherBridge($dispatcher));
 *     $router->match('GET', '/health'); // dispatches RouteMatched
 *
 * References: PSR-14; ARFA 1.3 P5; ADR-009; MatchObserver.
 *
 * @see https://www.php-fig.org/psr/psr-14/
 */
final readonly class EventDispatcherBridge implements MatchObserver
{
    public function __construct(
        private EventDispatcherInterface $dispatcher,
    ) {}

    #[\Override]
    public function routeMatched(MatchResult $result, int $durationNs): void
    {
        $this->dispatcher->dispatch(new RouteMatched($result, $durationNs));
    }

    #[\Override]
    public function routeMissed(string $method, RouteNotFoundException|MethodNotAllowedException $failure, int $durationNs): void
    {
        $this->dispatcher->dispatch(new RouteMissed($method, $failure, $durationNs));
    }
}
