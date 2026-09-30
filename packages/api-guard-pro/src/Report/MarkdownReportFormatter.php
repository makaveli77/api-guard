<?php

declare(strict_types=1);

namespace ApiGuard\Pro\Report;

use ApiGuard\Domain\Comparison\Change;
use ApiGuard\Domain\Comparison\Severity;
use ApiGuard\Pro\Application\IgnoredChange;
use ApiGuard\Pro\Application\ProComparisonResult;

final class MarkdownReportFormatter
{
    public function format(string $oldPath, string $newPath, ProComparisonResult $result): string
    {
        $lines = [
            '# API Guard Pro',
            '',
            sprintf('- Baseline: `%s`', $this->escapeInline($oldPath)),
            sprintf('- Updated: `%s`', $this->escapeInline($newPath)),
            sprintf('- Breaking changes: %d', $this->countSeverity($result->changes, Severity::BREAKING)),
            sprintf('- Non-breaking changes: %d', $this->countSeverity($result->changes, Severity::NON_BREAKING)),
            sprintf('- Informational changes: %d', $this->countSeverity($result->changes, Severity::INFO)),
            sprintf('- Ignored changes: %d', count($result->ignoredChanges)),
        ];

        foreach ([Severity::BREAKING, Severity::NON_BREAKING, Severity::INFO] as $severity) {
            $changes = array_values(array_filter(
                $result->changes,
                static fn (Change $change): bool => $change->severity === $severity
            ));
            if ($changes === []) {
                continue;
            }

            $lines[] = '';
            $lines[] = '## ' . match ($severity) {
                Severity::BREAKING => 'Breaking changes',
                Severity::NON_BREAKING => 'Non-breaking changes',
                Severity::INFO => 'Informational changes',
            };

            foreach ($changes as $change) {
                $lines[] = $this->formatChange($change);
            }
        }

        if ($result->ignoredChanges !== []) {
            $lines[] = '';
            $lines[] = '## Ignored changes';
            foreach ($result->ignoredChanges as $ignoredChange) {
                $lines[] = $this->formatIgnoredChange($ignoredChange);
            }
        }

        if ($result->changes === [] && $result->ignoredChanges === []) {
            $lines[] = '';
            $lines[] = 'No changes detected.';
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param list<Change> $changes
     */
    private function countSeverity(array $changes, Severity $severity): int
    {
        return count(array_filter($changes, static fn (Change $change): bool => $change->severity === $severity));
    }

    private function formatChange(Change $change): string
    {
        return sprintf(
            '- `%s` (%s): %s',
            $this->escapeInline($change->path),
            $change->type->value,
            $change->message
        );
    }

    private function formatIgnoredChange(IgnoredChange $ignoredChange): string
    {
        return sprintf(
            "- `%s` (%s): %s\n  Reason: %s",
            $this->escapeInline($ignoredChange->change->path),
            $ignoredChange->change->type->value,
            $ignoredChange->change->message,
            $ignoredChange->reason
        );
    }

    private function escapeInline(string $value): string
    {
        return str_replace(['\\', '`'], ['\\\\', '\\`'], $value);
    }
}