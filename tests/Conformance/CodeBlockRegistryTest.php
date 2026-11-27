<?php

declare(strict_types=1);

namespace KaririCode\Router\Tests\Conformance;

use KaririCode\Exception\ErrorCodeTable;
use KaririCode\Router\Exception\RouterCode;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * RouterCode against the ecosystem registry of record, CODE-BLOCKS.md of
 * kariricode/exception, read from the installed package (ADR-007).
 */
#[CoversNothing]
final class CodeBlockRegistryTest extends TestCase
{
    private const string REGISTRY = __DIR__ . '/../../vendor/kariricode/exception/CODE-BLOCKS.md';

    #[Test]
    public function routerCodeIsAValidCodeTable(): void
    {
        $table = new ErrorCodeTable(RouterCode::class);

        self::assertSame('RO', $table->prefix);
        self::assertSame([5000, 5099], [$table->block->first, $table->block->last]);
    }

    #[Test]
    public function theRegistryGivesTheRouterThisBlockAndPrefix(): void
    {
        $rows = array_values(array_filter(
            file(self::REGISTRY, FILE_IGNORE_NEW_LINES) ?: [],
            static fn(string $line): bool => str_contains($line, '| kariricode/router |'),
        ));

        self::assertCount(1, $rows, 'one block for kariricode/router');
        self::assertMatchesRegularExpression('/\A\| 5000–5099 \| RO \| kariricode\/router \| (released|claimed|proposed) \|/u', $rows[0]);
    }

    #[Test]
    public function codesFollowTheDocumentedLayout(): void
    {
        $ranges = [
            'definition' => [5000, 5009],
            'matching' => [5010, 5019],
            'generation' => [5020, 5029],
            'cache' => [5040, 5049],
            'files' => [5050, 5059],
        ];

        foreach (RouterCode::cases() as $case) {
            $inRange = array_filter($ranges, static fn(array $range): bool => $case->value >= $range[0] && $case->value <= $range[1]);
            self::assertCount(1, $inRange, $case->name . ' sits in a documented range');
        }
    }
}
