<?php

declare(strict_types=1);

namespace KaririCode\Router\Tests\Fixture;

use Psr\EventDispatcher\EventDispatcherInterface;

/** A PSR-14 dispatcher that keeps every event it receives. */
final class RecordingDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    #[\Override]
    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $event;
    }
}
