<?php

declare(strict_types=1);

namespace KaririCode\Router\Exception;

/**
 * The requested method is not registered for the matched URI.
 *
 * ARFA 1.3 P2: carries the allowed methods list so the HTTP layer
 * can produce a 405 with the RFC 9110 §8.5.1 Allow header.
 * Spec V4.0: final class with static factory.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7231#section-6.5.5 HTTP 405 Method Not Allowed
 */
final class MethodNotAllowedException extends RouterException
{
    /** @var list<string> */
    public readonly array $allowedMethods;

    /**
     * @param list<string> $allowedMethods
      *
      * @see https://datatracker.ietf.org/doc/html/rfc7231#section-6.5.5 HTTP 405 Method Not Allowed
     */
    public static function create(string $method, string $uri, array $allowedMethods): self
    {
        return new self($method, $uri, $allowedMethods);
    }

    /**
     * @param list<string> $allowedMethods
      *
      * @see https://datatracker.ietf.org/doc/html/rfc7231#section-6.5.5 HTTP 405 Method Not Allowed
     */
    public function __construct(
        string $method,
        string $uri,
        array $allowedMethods,
        ?\Throwable $previous = null,
    ) {
        $this->allowedMethods = $allowedMethods;
        $allowed = implode(', ', $allowedMethods);
        parent::__construct(
            "{$method} not allowed for {$uri}. Allowed: {$allowed}",
            5111,
            $previous,
        );
    }
}

