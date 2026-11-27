<?php

declare(strict_types=1);

namespace KaririCode\Router\Tests\Unit\Exception;

use KaririCode\Contract\Exception\Configuration;
use KaririCode\Contract\Exception\NotFound;
use KaririCode\Contract\Exception\Runtime;
use KaririCode\Contract\Exception\Validation;
use KaririCode\Router\Exception\MethodNotAllowedException;
use KaririCode\Router\Exception\RouteCacheException;
use KaririCode\Router\Exception\RouteCompilationException;
use KaririCode\Router\Exception\RouteFileException;
use KaririCode\Router\Exception\RouteNotFoundException;
use KaririCode\Router\Exception\RouterCode;
use KaririCode\Router\Exception\UrlGenerationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(RouterCode::class)]
#[CoversClass(RouteNotFoundException::class)]
#[CoversClass(MethodNotAllowedException::class)]
#[CoversClass(UrlGenerationException::class)]
#[CoversClass(RouteCompilationException::class)]
#[CoversClass(RouteFileException::class)]
#[CoversClass(RouteCacheException::class)]
final class RouterExceptionsTest extends TestCase
{
    #[Test]
    public function theCodesLiveInTheRegisteredBlockUnderTheirPrefix(): void
    {
        self::assertSame('RO', RouterCode::prefix());
        self::assertSame(5000, RouterCode::block()->first);
        self::assertSame(5099, RouterCode::block()->last);

        foreach (RouterCode::cases() as $case) {
            self::assertTrue(RouterCode::block()->contains($case->value), $case->name);
        }
    }

    /**
     * @return iterable<string, array{\Closure(): \Throwable, RouterCode, class-string, string}>
     */
    public static function failures(): iterable
    {
        yield 'route not found' => [static fn(): \Throwable => RouteNotFoundException::forRequest(), RouterCode::RouteNotFound, NotFound::class, '[RO_5010] No route matches the request method and path.'];
        yield 'unknown name' => [static fn(): \Throwable => RouteNotFoundException::named('users.show'), RouterCode::UnknownRouteName, NotFound::class, '[RO_5021] No route is named "users.show".'];
        yield 'method not allowed' => [static fn(): \Throwable => MethodNotAllowedException::allowing(['GET', 'HEAD']), RouterCode::MethodNotAllowed, NotFound::class, '[RO_5011] The request path does not accept the request method; it accepts GET, HEAD.'];
        yield 'missing parameter' => [static fn(): \Throwable => UrlGenerationException::missingParameter('users.show', 'id'), RouterCode::MissingParameter, Validation::class, '[RO_5020] Route "users.show" needs a value for the parameter "id".'];
        yield 'constraint' => [static fn(): \Throwable => UrlGenerationException::constraintViolation('users.show', 'id'), RouterCode::ConstraintViolation, Validation::class, '[RO_5022] The value for the parameter "id" of route "users.show" breaks its type.'];
        yield 'invalid pattern' => [static fn(): \Throwable => RouteCompilationException::invalidPattern('/x/{', 'a rule'), RouterCode::InvalidPattern, Configuration::class, '[RO_5000] Route pattern "/x/{" is invalid: a rule.'];
        yield 'duplicate parameter' => [static fn(): \Throwable => RouteCompilationException::duplicateParameter('/x', 'id'), RouterCode::DuplicateParameter, Configuration::class, '[RO_5001] Route pattern "/x" names the parameter "id" twice.'];
        yield 'unknown type' => [static fn(): \Throwable => RouteCompilationException::unknownParameterType('/x', 'date'), RouterCode::UnknownParameterType, Configuration::class, '[RO_5002] Route pattern "/x" uses "date", which is not one of int, string, slug, uuid or any.'];
        yield 'invalid method' => [static fn(): \Throwable => RouteCompilationException::invalidMethod('/x'), RouterCode::InvalidMethod, Configuration::class, '[RO_5003] Route "/x" needs one or more methods, each an RFC 9110 token.'];
        yield 'invalid handler' => [static fn(): \Throwable => RouteCompilationException::invalidHandler('/x'), RouterCode::InvalidHandler, Configuration::class, '[RO_5004] Route "/x" needs a handler: a non-empty string, a [class, method] pair of non-empty strings or a Closure.'];
        yield 'duplicate route' => [static fn(): \Throwable => RouteCompilationException::duplicateRoute('GET', '/x'), RouterCode::DuplicateRoute, Configuration::class, '[RO_5005] A route for GET "/x" is already registered.'];
        yield 'duplicate name' => [static fn(): \Throwable => RouteCompilationException::duplicateRouteName('home'), RouterCode::DuplicateRouteName, Configuration::class, '[RO_5006] A route named "home" is already registered.'];
        yield 'uncacheable route' => [static fn(): \Throwable => RouteCompilationException::uncacheableRoute('/x', 'why'), RouterCode::UncacheableRoute, Configuration::class, '[RO_5040] Route "/x" cannot be written to the route cache: why.'];
        yield 'uncacheable matcher' => [static fn(): \Throwable => RouteCompilationException::uncacheableMatcher(), RouterCode::UncacheableMatcher, Configuration::class, '[RO_5041] CachedMatcher can compile the table only from a CompositeRouteMatcher.'];
        yield 'cache key' => [static fn(): \Throwable => RouteCompilationException::invalidCacheKey(), RouterCode::InvalidCacheKey, Configuration::class, '[RO_5042] A route cache key needs 1 to 64 characters from A-Z, a-z, 0-9, "_" and ".", the set every PSR-16 cache accepts.'];
        yield 'route file missing' => [static fn(): \Throwable => RouteFileException::missing('/app/routes.php'), RouterCode::RouteFileMissing, Configuration::class, '[RO_5050] Route file "/app/routes.php" does not exist.'];
        yield 'route file invalid' => [static fn(): \Throwable => RouteFileException::notAnArray('/app/routes.php'), RouterCode::RouteFileInvalid, Configuration::class, '[RO_5051] Route file "/app/routes.php" must return an array of route definitions.'];
        yield 'route definition' => [static fn(): \Throwable => RouteFileException::invalidDefinition('entry 2 of the root', 'a rule'), RouterCode::InvalidRouteDefinition, Configuration::class, '[RO_5052] Route definition entry 2 of the root is invalid: a rule.'];
        yield 'cache directory' => [static fn(): \Throwable => RouteCacheException::directoryUnavailable('/var/cache'), RouterCode::CacheDirectoryUnavailable, Runtime::class, '[RO_5045] The route cache directory "/var/cache" does not exist and cannot be created.'];
        yield 'cache write' => [static fn(): \Throwable => RouteCacheException::writeFailed('/var/cache/r.php'), RouterCode::CacheWriteFailed, Runtime::class, '[RO_5046] The route cache at "/var/cache/r.php" could not be written.'];
    }

    /**
     * @param \Closure(): \Throwable $build
     * @param class-string           $family
     */
    #[Test]
    #[DataProvider('failures')]
    public function eachFactoryBuildsItsCodeFamilyAndMessage(\Closure $build, RouterCode $code, string $family, string $message): void
    {
        $failure = $build();

        self::assertInstanceOf($family, $failure);
        self::assertSame($code->value, $failure->getCode());
        self::assertSame($message, $failure->getMessage());
    }

    #[Test]
    public function methodNotAllowedCarriesTheAcceptedMethods(): void
    {
        self::assertSame(['GET', 'POST'], MethodNotAllowedException::allowing(['GET', 'POST'])->allowedMethods);
    }

    #[Test]
    public function cacheFailuresChainTheDriverError(): void
    {
        $cause = new RuntimeException('disk full');

        self::assertSame($cause, RouteCacheException::writeFailed('/x', $cause)->getPrevious());
        self::assertSame($cause, RouteCacheException::directoryUnavailable('/x', $cause)->getPrevious());
        self::assertNull(RouteCacheException::writeFailed('/x')->getPrevious());
    }

    #[Test]
    public function identifiersAreQuotedSafely(): void
    {
        self::assertSame('[RO_5021] No route is named "a\nb".', RouteNotFoundException::named("a\nb")->getMessage());
    }
}
