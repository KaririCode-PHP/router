<?php

declare(strict_types=1);

namespace KaririCode\Router\Exception;

use KaririCode\Exception\CodeBlock;
use KaririCode\Exception\ErrorCode;

/**
 * The router's error codes: block 5000–5099 of the ecosystem registry under
 * the prefix RO, so a log line that starts with `[RO_5011]` names this
 * package and one failure. The layout follows the life of a route:
 *
 *     5000–5009  route definitions: pattern, method, handler, duplicates
 *     5010–5019  matching
 *     5020–5029  URL generation
 *     5040–5049  the compiled route table and the stores that keep it
 *     5050–5059  route files read by ArrayRouteLoader
 *
 * 5030–5039 stays free for middleware. Until 4.1 the router documented
 * 5100–5159; the registry gave 5100–5199 to kariricode/dataflow, which had
 * released codes there, and moved the router by a constant −100 so its
 * layout survives (ADR-007).
 *
 * Preconditions: a code is stable API (registry rule 4). A case is never
 * renumbered and a retired value is never reused.
 *
 * Thread-safety: enum cases are immutable.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     try {
 *         $router->match('GET', '/missing');
 *     } catch (RouteNotFoundException $e) {
 *         assert($e->errorCode === RouterCode::RouteNotFound && $e->getCode() === 5010);
 *     }
 *
 * References: KaririCode Spec V4.3.3 §8.4; kariricode/exception ErrorCode
 * and CODE-BLOCKS.md; ADR-007.
 *
 * @see https://github.com/KaririCode-PHP/exception/blob/main/CODE-BLOCKS.md
 */
enum RouterCode: int implements ErrorCode
{
    case InvalidPattern = 5000;
    case DuplicateParameter = 5001;
    case UnknownParameterType = 5002;
    case InvalidMethod = 5003;
    case InvalidHandler = 5004;
    case DuplicateRoute = 5005;
    case DuplicateRouteName = 5006;
    case RouteNotFound = 5010;
    case MethodNotAllowed = 5011;
    case MissingParameter = 5020;
    case UnknownRouteName = 5021;
    case ConstraintViolation = 5022;
    case UncacheableRoute = 5040;
    case UncacheableMatcher = 5041;
    case InvalidCacheKey = 5042;
    case CacheDirectoryUnavailable = 5045;
    case CacheWriteFailed = 5046;
    case RouteFileMissing = 5050;
    case RouteFileInvalid = 5051;
    case InvalidRouteDefinition = 5052;

    #[\Override]
    public static function prefix(): string
    {
        return 'RO';
    }

    #[\Override]
    public static function block(): CodeBlock
    {
        return new CodeBlock(5000, 5099);
    }
}
