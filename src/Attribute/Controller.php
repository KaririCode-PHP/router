<?php

declare(strict_types=1);

namespace KaririCode\Router\Attribute;

/**
 * Declares class-level routing defaults for a controller.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): prefix, middleware, and name
 * prefix apply regardless of protocol — HTTP, WebSocket, SSE, gRPC.
 * Spec V4.0: final readonly class with promoted constructor properties.
 *
 * @see https://www.php.net/manual/en/language.attributes.php PHP Attributes
 *
 * All {@see Route} attributes on methods of this class inherit
 * the prefix, middleware, name prefix, host, and scheme from here.
 *
 *     #[Controller('/api/v1/users', middleware: [AuthMiddleware::class], namePrefix: 'users.')]
 *     class UserController { ... }
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Controller
{
    /**
     * @param list<class-string> $middleware
     */
    public function __construct(
        public string $prefix = '',
        public array $middleware = [],
        public ?string $namePrefix = null,
        public ?string $host = null,
        public ?string $scheme = null,
    ) {
    }
}
