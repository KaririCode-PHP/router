<?php

declare(strict_types=1);

namespace KaririCode\Router\Middleware;

use KaririCode\Router\Contract\Middleware;

/**
 * Onion-model middleware pipeline.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): operates on array context,
 * not PSR-7 objects. PSR-15 adapter lives in Integration/.
 *
 * Spec V4.0: final class, stateless — pipeline is rebuilt per request.
 *
 * Executes middleware in FIFO order, each receiving a `$next` callable
 * that invokes the remainder of the stack. The innermost handler is
 * the route's own action.
 *
 * @see https://www.php-fig.org/psr/psr-15/ PSR-15 (inspiration, decoupled)
 */
final class MiddlewarePipeline
{
    /**
     * @param list<Middleware>           $middlewares
     * @param callable(array<string,mixed>):mixed $handler   Innermost handler
     * @param array<string, mixed>      $context    Protocol-decoded request context
      *
      * @see https://www.php-fig.org/psr/psr-15/ PSR-15 (inspiration, decoupled)
     */
    public function execute(array $middlewares, callable $handler, array $context): mixed
    {
        $pipeline = $handler;

        // Build from inside out so first middleware in the list runs first
        foreach (array_reverse($middlewares) as $middleware) {
            $next = $pipeline;
            $pipeline = static fn (array $ctx): mixed => $middleware->handle($ctx, $next);
        }

        return $pipeline($context);
    }
}

