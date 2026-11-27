<?php

declare(strict_types=1);

namespace KaririCode\Router\Core;

use KaririCode\Router\Attribute\Controller;
use KaririCode\Router\Attribute\Route;
use ReflectionClass;
use ReflectionMethod;

/**
 * Builds routes from #[Route] attributes on the public methods of
 * controller classes, with the class-level defaults of #[Controller]:
 * path prefix, middleware that runs first, name prefix, host and scheme.
 * The handler of each route is the [class, method] pair.
 *
 * Preconditions: the classes exist and are autoloadable; give it the names
 * kariricode/class-discovery found, for instance. Constructors, destructors
 * and non-public methods are skipped. A #[Route] without a pattern uses
 * "/" plus the method name. The prefix and the pattern are joined as given,
 * so #[Route('/')] under #[Controller('/users')] is /users/.
 *
 * Thread-safety: keeps no state; each call returns new routes.
 *
 * Side effects: reads attributes through reflection; registers nothing.
 *
 * Example:
 *
 *     #[Controller('/users', middleware: ['auth'], namePrefix: 'users.')]
 *     final class UserController
 *     {
 *         #[Route('/{id:int}', method: 'GET', name: 'show')]
 *         public function show(): void {}
 *     }
 *
 *     foreach ((new AttributeRouteLoader())->loadFromClass(UserController::class) as $route) {
 *         $router->addRoute($route); // GET /users/{id:int}, named users.show
 *     }
 *
 * References: PHP attributes (RFC "Attributes v2"); SPEC-001 §2.4.
 *
 * @see https://www.php.net/manual/en/language.attributes.php
 */
final readonly class AttributeRouteLoader
{
    /**
     * @param list<class-string> $classes
     *
     * @return list<RouteEntry>
     */
    public function loadFromClasses(array $classes): array
    {
        return array_merge([], ...array_map($this->loadFromClass(...), $classes));
    }

    /**
     * @param class-string $class
     *
     * @return list<RouteEntry>
     */
    public function loadFromClass(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $controller = ($reflection->getAttributes(Controller::class)[0] ?? null)?->newInstance() ?? new Controller();
        $routes = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor() || $method->isDestructor()) {
                continue;
            }

            foreach ($method->getAttributes(Route::class) as $attribute) {
                $routes[] = self::entry($class, $method->getName(), $controller, $attribute->newInstance());
            }
        }

        return $routes;
    }

    /**
     * @param class-string $class
     */
    private static function entry(string $class, string $method, Controller $controller, Route $route): RouteEntry
    {
        $name = $route->name === null || $controller->namePrefix === null ? $route->name : $controller->namePrefix . $route->name;

        return new RouteEntry(
            methods: $route->methods,
            pattern: rtrim($controller->prefix, '/') . ($route->pattern ?? '/' . $method),
            handler: [$class, $method],
            name: $name,
            middleware: [...$controller->middleware, ...$route->middleware],
            defaults: $route->defaults,
            host: $route->host ?? $controller->host,
            scheme: $route->scheme ?? $controller->scheme,
        );
    }
}
