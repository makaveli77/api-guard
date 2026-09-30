<?php

declare(strict_types=1);

namespace ApiGuard\CLI;

use ApiGuard\Domain\Comparison\Change;
use ApiGuard\Domain\Comparison\Severity;

final class ComparisonReportFormatter
{
    /**
     * @param list<Change> $changes
     */
    public function format(string $oldPath, string $newPath, array $changes): string
    {
        usort($changes, static function (Change $left, Change $right): int {
            $severityOrder = [
                Severity::BREAKING->value => 0,
                Severity::NON_BREAKING->value => 1,
                Severity::INFO->value => 2,
            ];

            return [
                $severityOrder[$left->severity->value],
                $left->path,
                $left->type->value,
                $left->message,
            ] <=> [
                $severityOrder[$right->severity->value],
                $right->path,
                $right->type->value,
                $right->message,
            ];
        });

        $counts = [
            Severity::BREAKING->value => 0,
            Severity::NON_BREAKING->value => 0,
            Severity::INFO->value => 0,
        ];
        foreach ($changes as $change) {
            $counts[$change->severity->value]++;
        }

        $lines = [
            'API Guard',
            '',
            'Comparing:',
            '  ' . $oldPath,
            '  ' . $newPath,
            '',
            'Summary:',
            sprintf('  Breaking changes:     %d', $counts[Severity::BREAKING->value]),
            sprintf('  Non-breaking changes: %d', $counts[Severity::NON_BREAKING->value]),
            sprintf('  Informational changes: %d', $counts[Severity::INFO->value]),
            sprintf('  Total changes:        %d', count($changes)),
        ];

        if ($changes === []) {
            $lines[] = '';
            $lines[] = 'No changes detected.';

            return implode("\n", $lines) . "\n";
        }

        foreach ([Severity::BREAKING, Severity::NON_BREAKING, Severity::INFO] as $severity) {
            $severityChanges = array_filter($changes, static fn (Change $change): bool => $change->severity === $severity);
            if ($severityChanges === []) {
                continue;
            }

            $heading = match ($severity) {
                Severity::BREAKING => 'BREAKING CHANGES',
                Severity::NON_BREAKING => 'NON-BREAKING CHANGES',
                Severity::INFO => 'INFORMATIONAL CHANGES',
            };
            $marker = match ($severity) {
                Severity::BREAKING => '!',
                Severity::NON_BREAKING => '+',
                Severity::INFO => '-',
            };

            $lines[] = '';
            $lines[] = $heading;
            foreach ($severityChanges as $change) {
                $type = strtoupper(str_replace('_', ' ', $change->type->value));
                $lines[] = sprintf('  %s %s [%s]', $marker, $change->path, $type);
                $lines[] = '    ' . $change->message;
            }
        }

        return implode("\n", $lines) . "\n";
    }
}