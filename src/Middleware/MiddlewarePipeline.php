<?php

declare(strict_types=1);

namespace KaririCode\Router\Middleware;

use KaririCode\Router\Contract\Middleware;

/**
 * Runs a route's middleware around its handler, onion style: the first
 * middleware in the list is the outermost layer, sees the context first
 * and the result last. It works on the protocol-neutral context array of
 * Middleware, so the same pipeline serves every protocol.
 *
 * Preconditions: the middleware are instances; resolving MatchResult's
 * middleware definitions to objects is the dispatcher's job. An exception
 * from a layer or the handler propagates through the outer layers, which
 * may catch it.
 *
 * Thread-safety: keeps no state; a pipeline can run many requests.
 *
 * Side effects: those of the middleware and the handler.
 *
 * Example:
 *
 *     $pipeline = new MiddlewarePipeline();
 *     $response = $pipeline->execute(
 *         [new RequireTenant(), new MeasureLatency()],
 *         static fn(array $context): array => ['tenant' => $context['tenant']],
 *         ['tenant' => 'acme'],
 *     );
 *
 * References: ADR-002; PSR-15, the model it follows without PSR-7.
 *
 * @see https://www.php-fig.org/psr/psr-15/
 */
final readonly class MiddlewarePipeline
{
    /**
     * @param list<Middleware>                      $middleware outermost first
     * @param callable(array<string, mixed>): mixed $handler    the innermost layer
     * @param array<string, mixed>                  $context    protocol-decoded request data
     */
    public function execute(array $middleware, callable $handler, array $context): mixed
    {
        return $this->run($middleware, 0, $handler, $context);
    }

    /**
     * Runs the layer at $index, or the handler once the layers run out.
     *
     * @param list<Middleware>                      $middleware
     * @param callable(array<string, mixed>): mixed $handler
     * @param array<string, mixed>                  $context
     */
    private function run(array $middleware, int $index, callable $handler, array $context): mixed
    {
        $layer = $middleware[$index] ?? null;

        if ($layer === null) {
            return $handler($context);
        }

        return $layer->handle($context, fn(array $inner): mixed => $this->run($middleware, $index + 1, $handler, $inner));
    }
}
