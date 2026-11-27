<?php

declare(strict_types=1);

namespace KaririCode\Router\Tests\Integration;

use KaririCode\Router\Cache\PhpFileStore;
use KaririCode\Router\Exception\RouteCacheException;
use KaririCode\Router\Exception\RouterCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpFileStore::class)]
final class PhpFileStoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/kariricode-router-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            if ($entry instanceof \SplFileInfo) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
        }

        rmdir($this->directory);
    }

    #[Test]
    public function itWritesATableThatIncludeReadsBack(): void
    {
        $path = $this->directory . '/nested/routes.php';
        $store = new PhpFileStore($path);
        $table = ['format' => 1, 'static' => ['/x' => ['GET' => ['handler' => ['A', 'b'], 'name' => null]]], 'quote' => "it's \"fine\"\n"];

        self::assertNull($store->load());

        $store->save($table);

        self::assertFileExists($path);
        self::assertStringStartsWith("<?php\n\n// Compiled by kariricode/router", (string) file_get_contents($path));
        self::assertSame($table, $store->load());
        self::assertSame([$path], glob($this->directory . '/nested/*') ?: [], 'no temporary file is left behind');
    }

    #[Test]
    public function saveReplacesThePreviousTable(): void
    {
        $store = new PhpFileStore($this->directory . '/routes.php');
        $store->save(['version' => 1]);
        $store->save(['version' => 2]);

        self::assertSame(['version' => 2], $store->load());
    }

    #[Test]
    public function clearDeletesTheFileAndToleratesItsAbsence(): void
    {
        $path = $this->directory . '/routes.php';
        $store = new PhpFileStore($path);
        $store->save(['format' => 1]);

        $store->clear();
        $store->clear();

        self::assertFileDoesNotExist($path);
        self::assertNull($store->load());
    }

    #[Test]
    public function aFileThatReturnsNoArrayIsAMiss(): void
    {
        mkdir($this->directory);
        file_put_contents($this->directory . '/routes.php', "<?php\n\nreturn 'stale';\n");

        self::assertNull((new PhpFileStore($this->directory . '/routes.php'))->load());
    }

    #[Test]
    public function aDirectoryThatCannotBeCreatedFails(): void
    {
        mkdir($this->directory);
        file_put_contents($this->directory . '/file', 'x');
        $store = new PhpFileStore($this->directory . '/file/sub/routes.php');

        try {
            $store->save(['format' => 1]);
            self::fail('RouteCacheException expected');
        } catch (RouteCacheException $e) {
            self::assertSame(RouterCode::CacheDirectoryUnavailable, $e->errorCode);
            self::assertInstanceOf(\ErrorException::class, $e->getPrevious());
        }
    }

    #[Test]
    public function aWriteThatCannotFinishFailsAndCleansUp(): void
    {
        $path = $this->directory . '/routes.php';
        mkdir($path, 0o775, true);
        $store = new PhpFileStore($path);

        try {
            $store->save(['format' => 1]);
            self::fail('RouteCacheException expected');
        } catch (RouteCacheException $e) {
            self::assertSame(RouterCode::CacheWriteFailed, $e->errorCode);
            self::assertInstanceOf(\ErrorException::class, $e->getPrevious());
        }

        self::assertSame([$path], glob($this->directory . '/*') ?: [], 'the temporary file is removed');
    }
}
