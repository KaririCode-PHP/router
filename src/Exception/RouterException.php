<?php

declare(strict_types=1);

namespace KaririCode\Router\Exception;

/**
 * Base exception for all routing operations.
 *
 * ARFA 1.3 P2 (Reactive Flow Composition): exceptions propagate up
 * the dispatch pipeline so callers can distinguish route-not-found,
 * method-not-allowed, and compilation errors.
 * Spec V4.0: non-final by design — extended by MethodNotAllowedException,
 * RouteNotFoundException, RouteCompilationException, and UrlGenerationException.
 *
 * Error code ranges allocated to this package: 5100–5199.
 *
 *   5100–5109  Route definition errors
 *   5110–5119  Route matching errors
 *   5120–5129  URL generation errors
 *   5130–5139  Middleware errors
 *   5140–5149  Compilation / cache errors
 *   5150–5159  Integration bridge errors
 *
 * @see https://datatracker.ietf.org/doc/html/rfc9110#section-15 HTTP error semantics
 */
class RouterException extends \RuntimeException
{
    public function __construct(
        string $message,
        int $code = 5100,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
