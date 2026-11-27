<?php

declare(strict_types=1);

namespace KaririCode\Router\Contract;

/**
 * Protocol-agnostic middleware contract.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): accepts a generic context
 * array so the same middleware can serve HTTP, gRPC, WebSocket,
 * and CLI protocols — unlike PSR-15 which couples to PSR-7.
 *
 * Spec V4.0 §1.3: interface without "Interface" suffix.
 *
 * PSR-15 adapters are provided in the Integration layer.
 *
 * @see https://www.php-fig.org/psr/psr-15/ PSR-15 (inspiration, decoupled)
 */
interface Middleware
{
    /**
     * @param array<string, mixed>                $context Protocol-decoded context
     * @param callable(array<string,mixed>):mixed $next    Next handler in the pipeline
      *
      * @see https://www.php-fig.org/psr/psr-15/ PSR-15 (inspiration, decoupled)
     */
    public function handle(array $context, callable $next): mixed;
}

