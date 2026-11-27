<?php

declare(strict_types=1);

namespace KaririCode\Router\Core;

use KaririCode\Router\Attribute\Controller;
use KaririCode\Router\Attribute\Route;

/**
 * Discovers route definitions from PHP 8.4 attributes on controller classes.
 *
 * ARFA 1.3 P4 (Protocol Agnosticism): reads string-based method
 * annotations so any protocol can use attribute-based route discovery.
 * Spec V4.0: final class, stateless — each call produces a fresh route list.
 *
 * @see https://www.php.net/manual/en/language.attributes.php PHP 8 Attributes
 *
 * Reads {@see Controller} on the class and {@see Route} on each public
 * method, resolving inherited prefix, middleware, name prefix, host, and
 * scheme attributes.
 *
 * Integration with KaririCode\ClassDiscovery: pass discovered class names
 * to {@see self::loadFromClasses()} for automatic attribute scanning.
 */
final class AttributeRouteLoader
{
    /**
     * @param list<class-string> $classes
     * @return list<RouteEntry>
     */
    public function loadFromClasses(array $classes): array
    {
        $routes = [];

        foreach ($classes as $class) {
            foreach ($this->loadFromClass($class) as $route) {
                $routes[] = $route;
            }
        }

        return $routes;
    }

    /**
     * @param class-string $class
     * @return list<RouteEntry>
     */
    public function loadFromClass(string $class): array
    {
        $reflection = new \ReflectionClass($class);
        $controller = $this->extractControllerAttribute($reflection);

        $prefix = $controller?->prefix ?? '';
        $classMiddleware = $controller?->middleware ?? [];
        $namePrefix = $controller?->namePrefix;
        $host = $controller?->host;
        $scheme = $controller?->scheme;

        $routes = [];

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor() || $method->isDestructor()) {
                continue;
            }

            $routeAttributes = $method->getAttributes(Route::class);

            foreach ($routeAttributes as $attr) {
                $route = $attr->newInstance();

                $pattern = rtrim($prefix, '/') . ($route->pattern ?? '/' . $method->getName());

                $name = $route->name;
                if ($name !== null && $namePrefix !== null) {
                    $name = $namePrefix . $name;
                }

                $routes[] = new RouteEntry(
                    methods: $route->methods,
                    pattern: $pattern,
                    handler: [$class, $method->getName()],
                    name: $name,
                    middleware: [...$classMiddleware, ...$route->middleware],
                    defaults: $route->defaults,
                    host: $route->host ?? $host,
                    scheme: $route->scheme ?? $scheme,
                );
            }
        }

        return $routes;
    }

    private function extractControllerAttribute(\ReflectionClass $reflection): ?Controller
    {
        $attrs = $reflection->getAttributes(Controller::class);

        if ($attrs === []) {
            return null;
        }

        return $attrs[0]->newInstance();
    }
}
