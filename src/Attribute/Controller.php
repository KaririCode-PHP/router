<?php

declare(strict_types=1);

namespace KaririCode\Router\Attribute;

use Attribute;

/**
 * Class-level defaults for the #[Route] methods of a controller: a path
 * prefix, middleware that runs before each route's own, a name prefix, and
 * the host and scheme a route inherits when it sets none.
 *
 * Preconditions: AttributeRouteLoader joins the prefix and each pattern as
 * written, dropping only a trailing "/" of the prefix. Host and scheme are
 * carried to the route for the dispatcher; no matcher reads them yet
 * (ADR-005, proposed).
 *
 * Thread-safety: immutable.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     #[Controller('/api/v1/users', middleware: ['auth'], namePrefix: 'users.')]
 *     final class UserController
 *     {
 *         #[Route('/{id:int}', name: 'show')] // GET /api/v1/users/{id:int}, users.show
 *         public function show(): void {}
 *     }
 *
 * References: SPEC-001 §2.4; ADR-005; PHP attributes.
 *
 * @see https://www.php.net/manual/en/language.attributes.php
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Controller
{
    /**
     * @param list<string> $middleware definitions the dispatcher resolves, outermost first
     */
    public function __construct(
        public string $prefix = '',
        public array $middleware = [],
        public ?string $namePrefix = null,
        public ?string $host = null,
        public ?string $scheme = null,
    ) {}
}
