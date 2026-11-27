<?php

declare(strict_types=1);

namespace KaririCode\Router\Tests\Fixture;

use KaririCode\Router\Contract\MatchObserver;
use KaririCode\Router\Core\MatchResult;
use KaririCode\Router\Exception\MethodNotAllowedException;
use KaririCode\Router\Exception\RouteNotFoundException;

/** A MatchObserver that keeps every notification. */
final class RecordingObserver implements MatchObserver
{
    /** @var list<array{MatchResult, int}> */
    public array $matched = [];

    /** @var list<array{string, RouteNotFoundException|MethodNotAllowedException, int}> */
    public array $missed = [];

    #[\Override]
    public function routeMatched(MatchResult $result, int $durationNs): void
    {
        $this->matched[] = [$result, $durationNs];
    }

    #[\Override]
    public function routeMissed(string $method, RouteNotFoundException|MethodNotAllowedException $failure, int $durationNs): void
    {
        $this->missed[] = [$method, $failure, $durationNs];
    }
}
