<?php

declare(strict_types=1);

namespace KaririCode\Router\Cache;

use ErrorException;
use KaririCode\Router\Contract\RouteCacheStore;
use KaririCode\Router\Exception\RouteCacheException;

/**
 * Keeps the compiled route table as a PHP file that returns an array. With
 * OPcache on, PHP compiles the file once and every later include reads the
 * array from shared memory: no unserialize(), no file read per request.
 *
 * save() writes a temporary file next to the target and renames it over the
 * target, an atomic replacement on POSIX filesystems, so a request never
 * includes a half-written table; it then invalidates the OPcache entry.
 * PHP warnings from the filesystem become RouteCacheException.
 *
 * Preconditions: the path is the application's choice, never a value from
 * a request: load() includes, and so executes, the file. The directory is
 * created when missing. The file holds only what var_export() wrote.
 *
 * Thread-safety: concurrent save() calls each rename a complete file; the
 * last rename wins.
 *
 * Side effects: save() creates the directory and writes and renames files;
 * clear() deletes the file; both invalidate the OPcache entry.
 *
 * Example:
 *
 *     $store = new PhpFileStore(__DIR__ . '/var/cache/routes.php');
 *     $matcher = new CachedMatcher(new CompositeRouteMatcher(), $store);
 *
 * References: ADR-003 (OPcache route caching); PHP manual, opcache_invalidate
 * and rename (atomic on POSIX).
 *
 * @see https://www.php.net/manual/en/function.opcache-invalidate.php
 */
final readonly class PhpFileStore implements RouteCacheStore
{
    public function __construct(
        private string $path,
    ) {}

    #[\Override]
    public function load(): ?array
    {
        if (!is_file($this->path)) {
            return null;
        }

        return self::tableOrNull(include $this->path);
    }

    #[\Override]
    public function save(array $table): void
    {
        $directory = dirname($this->path);

        self::guarded(
            static function () use ($directory): void {
                try {
                    is_dir($directory) || mkdir($directory, 0o775, true);
                } catch (ErrorException $error) {
                    // Another process may have created it in the meantime.
                    is_dir($directory) || throw $error;
                }
            },
            static fn(ErrorException $error): RouteCacheException => RouteCacheException::directoryUnavailable($directory, $error),
        );

        $temporary = sprintf('%s.%s.tmp', $this->path, bin2hex(random_bytes(6)));
        $contents = "<?php\n\n// Compiled by kariricode/router CachedMatcher::warm(). Do not edit.\n\nreturn "
            . var_export($table, true) . ";\n";

        self::guarded(
            function () use ($temporary, $contents): void {
                file_put_contents($temporary, $contents, LOCK_EX);
                rename($temporary, $this->path);
            },
            function (ErrorException $error) use ($temporary): RouteCacheException {
                if (is_file($temporary)) {
                    unlink($temporary);
                }

                return RouteCacheException::writeFailed($this->path, $error);
            },
        );

        $this->invalidate();
    }

    #[\Override]
    public function clear(): void
    {
        if (is_file($this->path)) {
            self::guarded(
                fn(): bool => unlink($this->path),
                fn(ErrorException $error): RouteCacheException => RouteCacheException::writeFailed($this->path, $error),
            );
        }

        $this->invalidate();
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function tableOrNull(mixed $data): ?array
    {
        return is_array($data) ? $data : null;
    }

    private function invalidate(): void
    {
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->path, true);
        }
    }

    /**
     * Runs $operation with PHP warnings turned into ErrorException and maps
     * one to the exception $failure returns.
     *
     * @param callable(): mixed                                $operation
     * @param callable(ErrorException): RouteCacheException $failure
     *
     * @throws RouteCacheException
     */
    private static function guarded(callable $operation, callable $failure): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            $operation();
        } catch (ErrorException $error) {
            throw $failure($error);
        } finally {
            restore_error_handler();
        }
    }
}
