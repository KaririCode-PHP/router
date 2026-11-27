<?php

declare(strict_types=1);

namespace KaririCode\Router\Tests\Fixture;

use KaririCode\Router\Contract\Middleware;

/** Appends its label to $context['trace'] on the way in and to the result on the way out. */
final readonly class TraceMiddleware implements Middleware
{
    public function __construct(
        private string $label,
        private bool $shortCircuit = false,
    ) {}

    #[\Override]
    public function handle(array $context, callable $next): mixed
    {
        $trace = is_string($context['trace'] ?? null) ? $context['trace'] : '';

        if ($this->shortCircuit) {
            return $trace . $this->label . '!';
        }

        $result = $next([...$context, 'trace' => $trace . $this->label . '>']);

        return (is_string($result) ? $result : '') . '<' . $this->label;
    }
}
