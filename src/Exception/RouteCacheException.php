<?php

declare(strict_types=1);

namespace KaririCode\Router\Exception;

use KaririCode\Exception\RuntimeException;
use Throwable;

/**
 * The compiled route table could not be stored: the cache directory cannot
 * be created (5045), or the store refused the write (5046). Both are
 * infrastructure faults, so the same warm-up may succeed once the disk or
 * the cache server recovers.
 *
 * Preconditions: the driver's error travels as the previous exception. The
 * message names the location (a path or a cache key), never the table.
 *
 * Thread-safety: an exception belongs to the call that threw it.
 *
 * Side effects: none.
 *
 * Example:
 *
 *     try {
 *         $cachedMatcher->warm();
 *     } catch (RouteCacheException $e) {
 *         $logger->error($e->getMessage(), ['exception' => $e]);
 *     }
 *
 * References: KaririCode Spec V4.3.3 §8.4; ADR-003 (route cache).
 *
 * @see https://www.php.net/manual/en/function.rename.php
 */
final class RouteCacheException extends RuntimeException
{
    public static function directoryUnavailable(string $directory, ?Throwable $previous = null): self
    {
        return new self(
            RouterCode::CacheDirectoryUnavailable,
            sprintf('The route cache directory %s does not exist and cannot be created.', self::quote($directory)),
            $previous,
        );
    }

    public static function writeFailed(string $location, ?Throwable $previous = null): self
    {
        return new self(
            RouterCode::CacheWriteFailed,
            sprintf('The route cache at %s could not be written.', self::quote($location)),
            $previous,
        );
    }
}
