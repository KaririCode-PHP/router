<?php

declare(strict_types=1);

namespace KaririCode\Router\Contract;

use KaririCode\Router\Core\NormalizedRequest;

/**
 * Reads the method and the path from one protocol's native request. Each
 * protocol package implements it in its own namespace (kariricode/http has
 * HttpNormalizer, kariricode/grpc has GrpcNormalizer), so the dependency
 * always points at the router and a new protocol needs one new class and no
 * change here.
 *
 * Preconditions: normalize() is called only for a request canNormalize()
 * accepted; it returns the uppercase method and the path without query
 * string. The parameter is `object` because the router cannot name the
 * protocol types (accepted deviation, ADR-002).
 *
 * Thread-safety: implementations keep no state.
 *
 * Side effects: none; the original request is carried, not changed.
 *
 * Example:
 *
 *     final class CliNormalizer implements RequestNormalizer
 *     {
 *         public function canNormalize(object $request): bool { return $request instanceof CliInput; }
 *
 *         public function normalize(object $request): NormalizedRequest
 *         {
 *             assert($request instanceof CliInput);
 *
 *             return new NormalizedRequest('RUN', '/' . $request->command, $request);
 *         }
 *     }
 *
 * References: ADR-002 (protocol-agnostic design); tasks/todo.md (the V4.1
 * protocol packages); RFC 3986 §3.
 *
 * @see https://www.rfc-editor.org/rfc/rfc3986#section-3
 */
interface RequestNormalizer
{
    public function canNormalize(object $request): bool;

    public function normalize(object $request): NormalizedRequest;
}
