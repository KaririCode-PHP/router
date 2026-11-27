<?php

declare(strict_types=1);

namespace KaririCode\Router\Core;

use Closure;
use KaririCode\Router\Exception\RouteFileException;

/**
 * Builds routes from plain PHP arrays, usually a route file that returns
 * them, so routes can live in configuration instead of boot code. It
 * replaces 4.0's ConfiguratorBridge, which needed no configurator and
 * registered into a registry instead of returning routes like
 * AttributeRouteLoader does.
 *
 * An entry is a route or a group:
 *
 *     return [
 *         ['GET', '/users', [UserController::class, 'index'], 'users.index'],
 *         ['GET|POST', '/search', 'SearchAction', 'search', 'middleware' => ['throttle'], 'defaults' => ['page' => '1']],
 *         ['group' => '/admin', 'middleware' => ['auth.admin'], 'name_prefix' => 'admin.', 'routes' => [
 *             ['GET', '/stats', [AdminController::class, 'stats'], 'stats'],
 *         ]],
 *     ];
 *
 * A route has its methods first ("GET", "GET|POST" or a list), then the
 * pattern and the handler, then an optional name; "middleware" and
 * "defaults" are optional keys. A group has "group" (the prefix) and
 * "routes", optionally "middleware" and "name_prefix"; groups nest.
 *
 * Preconditions: entries are checked as they are read and the first entry
 * of the wrong shape throws RouteFileException with its position; the
 * routes themselves are then checked by RouteEntry. The path given to
 * loadFile() is the application's choice: the file is executed.
 *
 * Thread-safety: keeps no state; each call returns new routes.
 *
 * Side effects: loadFile() includes, and so runs, the file.
 *
 * Example:
 *
 *     foreach ((new ArrayRouteLoader())->loadFile(__DIR__ . '/config/routes.php') as $route) {
 *         $router->addRoute($route);
 *     }
 *
 * References: SPEC-001 §2.5 (route files); ADR-002; ADR-009.
 *
 * @see https://www.php.net/manual/en/function.require.php
 */
final readonly class ArrayRouteLoader
{
    /**
     * @throws RouteFileException when the file is missing, returns no array or holds an invalid entry
     *
     * @return list<RouteEntry>
     */
    public function loadFile(string $path): array
    {
        if (!is_file($path)) {
            throw RouteFileException::missing($path);
        }

        return $this->loadReturned(require $path, $path);
    }

    /**
     * @param array<array-key, mixed> $definitions
     *
     * @throws RouteFileException when an entry has the wrong shape
     *
     * @return list<RouteEntry>
     */
    public function load(array $definitions): array
    {
        return self::entries($definitions, 'the root');
    }

    /**
     * @return list<RouteEntry>
     */
    private function loadReturned(mixed $definitions, string $path): array
    {
        return is_array($definitions) ? $this->load($definitions) : throw RouteFileException::notAnArray($path);
    }

    /**
     * @param array<array-key, mixed> $definitions
     *
     * @return list<RouteEntry>
     */
    private static function entries(array $definitions, string $where): array
    {
        $routes = [];

        foreach (array_values($definitions) as $index => $definition) {
            $position = sprintf('entry %d of %s', $index + 1, $where);

            if (!is_array($definition)) {
                throw RouteFileException::invalidDefinition($position, 'an entry is an array');
            }

            array_push($routes, ...(array_key_exists('group', $definition) ? self::group($definition, $position) : [self::route($definition, $position)]));
        }

        return $routes;
    }

    /**
     * @param array<array-key, mixed> $definition
     *
     * @return list<RouteEntry>
     */
    private static function group(array $definition, string $position): array
    {
        $prefix = $definition['group'];
        $routes = $definition['routes'] ?? null;
        $namePrefix = $definition['name_prefix'] ?? null;

        if (!is_string($prefix) || !is_array($routes) || ($namePrefix !== null && !is_string($namePrefix))) {
            throw RouteFileException::invalidDefinition($position, 'a group has a string "group", a "routes" array and an optional string "name_prefix"');
        }

        $middleware = self::strings($definition['middleware'] ?? [], $position, 'middleware');

        return array_map(
            static function (RouteEntry $route) use ($prefix, $middleware, $namePrefix): RouteEntry {
                $route = $route->withPrefix($prefix)->withPrependedMiddleware($middleware);

                return $namePrefix === null ? $route : $route->withNamePrefix($namePrefix);
            },
            self::entries($routes, sprintf('group "%s"', $prefix)),
        );
    }

    /**
     * @param array<array-key, mixed> $definition
     */
    private static function route(array $definition, string $position): RouteEntry
    {
        [$pattern, $handler, $name] = self::routeFields($definition, $position);

        return new RouteEntry(
            methods: self::methods($definition[0] ?? null, $position),
            pattern: $pattern,
            handler: $handler,
            name: $name,
            middleware: self::strings($definition['middleware'] ?? [], $position, 'middleware'),
            defaults: self::stringMap($definition['defaults'] ?? [], $position),
        );
    }

    /**
     * @param array<array-key, mixed> $definition
     *
     * @return array{string, string|array{0: string, 1: string}|Closure, ?string}
     */
    private static function routeFields(array $definition, string $position): array
    {
        return self::fields($definition[1] ?? null, $definition[2] ?? null, $definition[3] ?? null)
            ?? throw RouteFileException::invalidDefinition($position, 'a route is [methods, pattern, handler, name?] with a string pattern, a string, [class, method] or Closure handler and an optional string name');
    }

    /**
     * @return array{string, string|array{0: string, 1: string}|Closure, ?string}|null
     */
    private static function fields(mixed $pattern, mixed $handler, mixed $name): ?array
    {
        return is_string($pattern) && self::isHandler($handler) && ($name === null || is_string($name)) ? [$pattern, $handler, $name] : null;
    }

    /**
     * @return list<string>
     */
    private static function methods(mixed $methods, string $position): array
    {
        return is_string($methods) ? explode('|', $methods) : self::strings($methods, $position, 'methods');
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value, string $position, string $key): array
    {
        if (!is_array($value) || !array_is_list($value) || !array_all($value, static fn(mixed $item): bool => is_string($item))) {
            throw RouteFileException::invalidDefinition($position, sprintf('"%s" is a list of strings', $key));
        }

        /** @var list<string> $value */
        return $value;
    }

    /**
     * @return array<string, string>
     */
    private static function stringMap(mixed $value, string $position): array
    {
        if (!is_array($value) || !array_all($value, static fn(mixed $item, int|string $key): bool => is_string($key) && is_string($item))) {
            throw RouteFileException::invalidDefinition($position, '"defaults" maps parameter names to strings');
        }

        /** @var array<string, string> $value */
        return $value;
    }

    /**
     * @phpstan-assert-if-true string|array{0: string, 1: string}|Closure $handler
     *
     * @psalm-assert-if-true string|array{0: string, 1: string}|Closure $handler
     */
    private static function isHandler(mixed $handler): bool
    {
        return is_string($handler)
            || $handler instanceof Closure
            || (is_array($handler) && array_is_list($handler) && count($handler) === 2 && is_string($handler[0]) && is_string($handler[1]));
    }
}
