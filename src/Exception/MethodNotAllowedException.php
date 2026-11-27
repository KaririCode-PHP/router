<?php

declare(strict_types=1);

namespace KaririCode\Router\Exception;

use KaririCode\Exception\NotFoundException;

/**
 * The request path exists, but no route on it accepts the request method
 * (5011). It carries the methods the path does accept, which an HTTP edge
 * sends in the Allow header of its 405 response. It stays in the NotFound
 * family: an edge that does not know this class answers 404, which is
 * still true for that method.
 *
 * Preconditions: allowedMethods is never empty; the matcher raises
 * RouteNotFoundException when no route has the path at all. The message
 * lists the accepted methods, which come from the route definitions, and
 * never the method the client sent (errata E-047).
 *
 * Thread-safety: an exception belongs to the call that threw it.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     try {
 *         $result = $router->match('DELETE', '/users');
 *     } catch (MethodNotAllowedException $e) {
 *         return $responses->methodNotAllowed(allow: implode(', ', $e->allowedMethods));
 *     }
 *
 * References: KaririCode Spec V4.3.3 §8.4; RFC 9110 §15.5.6 (405) and
 * §10.2.1 (Allow); ADR-007.
 *
 * @see https://www.rfc-editor.org/rfc/rfc9110#section-15.5.6
 */
final class MethodNotAllowedException extends NotFoundException
{
    /** @var list<string> uppercase methods the path accepts, in registration order */
    public private(set) array $allowedMethods = [];

    /**
     * @param non-empty-list<string> $allowedMethods
     */
    public static function allowing(array $allowedMethods): self
    {
        $failure = new self(
            RouterCode::MethodNotAllowed,
            sprintf('The request path does not accept the request method; it accepts %s.', implode(', ', $allowedMethods)),
        );
        $failure->allowedMethods = $allowedMethods;

        return $failure;
    }
}
