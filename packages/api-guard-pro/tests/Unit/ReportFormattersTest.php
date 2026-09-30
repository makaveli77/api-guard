<?php

declare(strict_types=1);

namespace ApiGuard\Pro\Tests\Unit;

use ApiGuard\Domain\Comparison\Change;
use ApiGuard\Domain\Comparison\ChangeType;
use ApiGuard\Domain\Comparison\Severity;
use ApiGuard\Pro\Application\IgnoredChange;
use ApiGuard\Pro\Application\ProComparisonResult;
use ApiGuard\Pro\Report\JsonReportFormatter;
use ApiGuard\Pro\Report\MarkdownReportFormatter;
use JsonException;
use PHPUnit\Framework\TestCase;

final class ReportFormattersTest extends TestCase
{
    /** @throws JsonException */
    public function testJsonReportIsVersionedDeterministicAndRetainsIgnoredReasons(): void
    {
        $formatter = new JsonReportFormatter();
        $result = $this->comparisonResult();
        $report = $formatter->format('old.yaml', 'new.yaml', $result);

        self::assertSame($report, $formatter->format('old.yaml', 'new.yaml', $result));
        $decoded = json_decode($report, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $summary = $decoded['summary'] ?? null;
        self::assertIsArray($summary);
        $ignoredChanges = $decoded['ignored_changes'] ?? null;
        self::assertIsArray($ignoredChanges);
        $firstIgnoredChange = $ignoredChanges[0] ?? null;
        self::assertIsArray($firstIgnoredChange);
        self::assertSame(1, $decoded['schema_version']);
        self::assertSame(['old' => 'old.yaml', 'new' => 'new.yaml'], $decoded['comparison']);
        self::assertSame(1, $summary['breaking_changes'] ?? null);
        self::assertSame(1, $summary['ignored_changes'] ?? null);
        self::assertSame(1, $summary['ignored_breaking_changes'] ?? null);
        self::assertTrue($summary['has_breaking_changes'] ?? false);
        self::assertSame('Existing consumer migration.', $firstIgnoredChange['reason'] ?? null);
    }

    public function testMarkdownReportGroupsChangesAndIncludesIgnoreReason(): void
    {
        $report = (new MarkdownReportFormatter())->format('old.yaml', 'new.yaml', $this->comparisonResult());

        self::assertStringContainsString('## Breaking changes', $report);
        self::assertStringContainsString('## Ignored changes', $report);
        self::assertStringContainsString('`/users`', $report);
        self::assertStringContainsString('Reason: Existing consumer migration.', $report);
    }

    private function comparisonResult(): ProComparisonResult
    {
        return new ProComparisonResult(
            [new Change(ChangeType::PATH, Severity::BREAKING, '/orders', 'Endpoint was removed.')],
            [new IgnoredChange(
                new Change(ChangeType::RESPONSE_SCHEMA, Severity::BREAKING, '/users', 'Email was removed.'),
                'Existing consumer migration.'
            )]
        );
    }
}