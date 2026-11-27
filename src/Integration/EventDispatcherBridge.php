<?php

declare(strict_types=1);

namespace KaririCode\Router\Integration;

use KaririCode\Router\Core\MatchResult;

/**
 * Bridge to KaririCode\EventDispatcher for route lifecycle events.
 *
 * ARFA 1.3 P5 (Continuous Observability): emits events on route
 * matched, route not found, method not allowed, middleware executed.
 * Enables observability dashboards and audit trails.
 *
 * Spec V4.0: final class. PSR-14 compatible via duck-typed dispatcher.
 *
 * Requires: kariricode/event-dispatcher ^4.0
 *
 * @see https://www.php-fig.org/psr/psr-14/ PSR-14 Event Dispatcher
 */
final class EventDispatcherBridge
{
    /**
     * @param object $dispatcher Any PSR-14 compatible dispatcher with dispatch(object):object
      *
      * @see https://www.php-fig.org/psr/psr-14/ PSR-14 Event Dispatcher
     */
    public function __construct(
        private readonly object $dispatcher,
    ) {
    }

    public function routeMatched(MatchResult $result, float $durationMs): void
    {
        $this->emit('router.matched', [
            'route' => $result->routeName() ?? $result->route->pattern,
            'method' => $result->route->methods[0] ?? 'UNKNOWN',
            'params' => $result->parameters->all(),
            'middleware_count' => count($result->middleware),
            'duration_ms' => round($durationMs, 3),
        ]);
    }

    public function routeNotFound(string $method, string $uri, float $durationMs): void
    {
        $this->emit('router.not_found', [
            'method' => $method,
            'uri' => $uri,
            'duration_ms' => round($durationMs, 3),
        ]);
    }

    public function methodNotAllowed(string $method, string $uri, array $allowed): void
    {
        $this->emit('router.method_not_allowed', [
            'method' => $method,
            'uri' => $uri,
            'allowed' => $allowed,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
      *
      * @see https://www.php-fig.org/psr/psr-14/ PSR-14 Event Dispatcher
     */
    private function emit(string $eventName, array $payload): void
    {
        if (method_exists($this->dispatcher, 'dispatch')) {
            $event = new \stdClass();
            $event->name = $eventName;
            $event->payload = $payload;
            $event->timestamp = hrtime(true);

            $this->dispatcher->dispatch($event);
        }
    }
}

