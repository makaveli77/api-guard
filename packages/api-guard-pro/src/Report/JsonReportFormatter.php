<?php

declare(strict_types=1);

namespace ApiGuard\Pro\Report;

use ApiGuard\Domain\Comparison\Change;
use ApiGuard\Domain\Comparison\Severity;
use ApiGuard\Pro\Application\IgnoredChange;
use ApiGuard\Pro\Application\ProComparisonResult;
use JsonException;

final class JsonReportFormatter
{
    /**
     * @throws JsonException
     */
    public function format(string $oldPath, string $newPath, ProComparisonResult $result): string
    {
        $breaking = 0;
        $nonBreaking = 0;
        $informational = 0;
        $ignoredBreaking = 0;
        foreach ($result->changes as $change) {
            match ($change->severity) {
                Severity::BREAKING => $breaking++,
                Severity::NON_BREAKING => $nonBreaking++,
                Severity::INFO => $informational++,
            };
        }
        foreach ($result->ignoredChanges as $ignoredChange) {
            if ($ignoredChange->change->severity === Severity::BREAKING) {
                $ignoredBreaking++;
            }
        }

        $report = [
            'schema_version' => 1,
            'comparison' => [
                'old' => $oldPath,
                'new' => $newPath,
            ],
            'summary' => [
                'breaking_changes' => $breaking,
                'non_breaking_changes' => $nonBreaking,
                'informational_changes' => $informational,
                'ignored_changes' => count($result->ignoredChanges),
                'ignored_breaking_changes' => $ignoredBreaking,
                'total_changes' => count($result->changes) + count($result->ignoredChanges),
                'has_breaking_changes' => $result->hasBreakingChanges(),
            ],
            'changes' => array_map($this->changeData(...), $result->changes),
            'ignored_changes' => array_map($this->ignoredChangeData(...), $result->ignoredChanges),
        ];

        return json_encode(
            $report,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . "\n";
    }

    /**
     * @return array{type: string, severity: string, path: string, message: string}
     */
    private function changeData(Change $change): array
    {
        return [
            'type' => $change->type->value,
            'severity' => $change->severity->value,
            'path' => $change->path,
            'message' => $change->message,
        ];
    }

    /**
     * @return array{type: string, severity: string, path: string, message: string, reason: string}
     */
    private function ignoredChangeData(IgnoredChange $ignoredChange): array
    {
        return [
            ...$this->changeData($ignoredChange->change),
            'reason' => $ignoredChange->reason,
        ];
    }
}