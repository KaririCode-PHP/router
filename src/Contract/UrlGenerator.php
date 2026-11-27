<?php

declare(strict_types=1);

namespace KaririCode\Router\Contract;

/**
 * Generates URIs from named routes and parameter maps.
 *
 * ARFA 1.3 P1 (Immutable State Transformation): generate() is a
 * pure function — same inputs always produce the same URI string.
 * Spec V4.0 §1.3: interface without "Interface" suffix.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc3986#section-5.3 URI reference resolution
 */
interface UrlGenerator
{
    /**
     * @param array<string, string|int> $parameters
     * @param array<string, string>     $query
     *
     * @throws \KaririCode\Router\Exception\UrlGenerationException
     */
    public function generate(string $routeName, array $parameters = [], array $query = []): string;
}
