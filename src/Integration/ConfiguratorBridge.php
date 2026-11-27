<?php

declare(strict_types=1);

namespace KaririCode\Router\Integration;

use KaririCode\Router\Core\RouteEntry;
use KaririCode\Router\Core\RouteGroup;
use KaririCode\Router\Core\RouteRegistry;

/**
 * Bridge to KaririCode\Configurator for file-based route definitions.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): loads route arrays with string-based
 * methods so any protocol can define routes via config files.
 * Spec V4.0: final class.
 *
 * Loads route definitions from PHP array files (config/routes.php)
 * and registers them with the RouteRegistry.
 *
 * File format:
 *
 *     return [
 *         ['GET', '/users', [UserController::class, 'index'], 'users.index'],
 *         ['POST', '/users', [UserController::class, 'store'], 'users.store'],
 *         ['group' => '/admin', 'middleware' => [AdminAuth::class], 'routes' => [
 *             ['GET', '/dashboard', [AdminController::class, 'dashboard'], 'admin.dashboard'],
 *         ]],
 *     ];
 *
 * Requires: kariricode/configurator ^4.0 (optional, works with plain PHP arrays)
 *
 * @see https://www.php-fig.org/psr/psr-11/ PSR-11 Container Interface
 */
final class ConfiguratorBridge
{
    /**
     * @param list<array<string, mixed>> $definitions Route definition arrays
     */
    public function loadInto(RouteRegistry $registry, array $definitions): void
    {
        foreach ($definitions as $def) {
            if (isset($def['group'])) {
                $this->loadGroup($registry, $def);
            } else {
                $this->loadRoute($registry, $def);
            }
        }
    }

    /**
     * Load from a PHP file that returns an array of route definitions.
     */
    public function loadFile(RouteRegistry $registry, string $filePath): void
    {
        if (!is_file($filePath)) {
            throw new \InvalidArgumentException("Route file not found: {$filePath}");
        }

        /** @var list<array<string, mixed>> $definitions */
        $definitions = require $filePath;

        if (!is_array($definitions)) {
            throw new \InvalidArgumentException("Route file must return an array: {$filePath}");
        }

        $this->loadInto($registry, $definitions);
    }

    /**
     * @param array<int|string, mixed> $def
     */
    private function loadRoute(RouteRegistry $registry, array $def): void
    {
        $methods = is_string($def[0])
            ? array_map(strtoupper(...), explode('|', $def[0]))
            : (is_array($def[0]) ? array_map(strtoupper(...), $def[0]) : ['GET']);

        $registry->addRoute(new RouteEntry(
            methods: $methods,
            pattern: (string) ($def[1] ?? '/'),
            handler: $def[2] ?? throw new \InvalidArgumentException('Route handler is required'),
            name: $def[3] ?? null,
            middleware: $def['middleware'] ?? [],
            defaults: $def['defaults'] ?? [],
        ));
    }

    /**
     * @param array<string, mixed> $def
     */
    private function loadGroup(RouteRegistry $registry, array $def): void
    {
        $group = new RouteGroup(
            prefix: (string) $def['group'],
            middleware: $def['middleware'] ?? [],
            namePrefix: $def['name_prefix'] ?? null,
        );

        $routes = $def['routes'] ?? [];

        $innerRegistry = new RouteRegistry();
        $this->loadInto($innerRegistry, $routes);

        foreach ($innerRegistry->routes() as $route) {
            $registry->addRoute(
                $route->withPrefix((string) $def['group'])
                    ->withPrependedMiddleware($def['middleware'] ?? []),
            );
        }
    }
}
