<?php

declare(strict_types=1);

namespace KaririCode\Router\Attribute;

use Attribute;
use KaririCode\Router\Enum\HttpMethod;

/**
 * Declares a route on a controller method; AttributeRouteLoader turns it
 * into a RouteEntry whose handler is [class, method]. The attribute is
 * repeatable, so one method can serve several patterns.
 *
 * Preconditions: methods are strings of any protocol ('GET', 'UNARY',
 * 'SUBSCRIBE'), stored uppercase; the deprecated HttpMethod enum is still
 * accepted. A null pattern means "/" plus the method name. Validation of
 * the pattern and methods happens when the loader builds the RouteEntry.
 *
 * Thread-safety: immutable.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     #[Route('/users/{id:int}', method: ['GET', 'HEAD'], name: 'users.show')]
 *     #[Route('/members/{id:int}', name: 'members.show')]
 *     public function show(): Response { ... }
 *
 * References: SPEC-001 §2.4; ADR-002 (method strings); PHP attributes.
 *
 * @see https://www.php.net/manual/en/language.attributes.php
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class Route
{
    /** @var list<string> uppercase method strings */
    public array $methods;

    /**
     * Accepting HttpMethod keeps 4.0 attribute code working.
     *
     * @psalm-suppress DeprecatedClass
     *
     * @param string|HttpMethod|list<string|HttpMethod> $method
     * @param list<string>                              $middleware definitions the dispatcher resolves, outermost first
     * @param array<string, string>                     $defaults   values for parameters the path did not provide
     */
    public function __construct(
        public ?string $pattern = null,
        string|HttpMethod|array $method = 'GET',
        public ?string $name = null,
        public array $middleware = [],
        public array $defaults = [],
        public ?string $host = null,
        public ?string $scheme = null,
    ) {
        $this->methods = array_map(
            static fn(string|HttpMethod $method): string => strtoupper($method instanceof HttpMethod ? $method->value : $method),
            is_array($method) ? $method : [$method],
        );
    }
}
