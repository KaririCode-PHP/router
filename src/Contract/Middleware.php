<?php

declare(strict_types=1);

namespace KaririCode\Router\Contract;

/**
 * A layer of the onion MiddlewarePipeline runs around a route's handler.
 * The context is an array the protocol adapter fills, not a PSR-7 request,
 * so the same middleware serves HTTP, gRPC, WebSocket and CLI routes; PSR-15
 * middleware stays in the HTTP package, which adapts it.
 *
 * Preconditions: call $next at most once, with the context the inner layers
 * should see, or return without calling it to short-circuit the rest.
 *
 * Thread-safety: keep no per-request state on the instance; the pipeline
 * may reuse it across requests.
 *
 * Side effects: whatever the layer does (logging, auth checks); the
 * pipeline adds none.
 *
 * Example:
 *
 *     final class RequireTenant implements Middleware
 *     {
 *         public function handle(array $context, callable $next): mixed
 *         {
 *             return isset($context['tenant']) ? $next($context) : ['error' => 'tenant required'];
 *         }
 *     }
 *
 * References: ADR-002; PSR-15, the model it decouples from.
 *
 * @see https://www.php-fig.org/psr/psr-15/
 */
interface Middleware
{
    /**
     * @param array<string, mixed>                  $context protocol-decoded request data
     * @param callable(array<string, mixed>): mixed $next    the rest of the pipeline
     */
    public function handle(array $context, callable $next): mixed;
}
