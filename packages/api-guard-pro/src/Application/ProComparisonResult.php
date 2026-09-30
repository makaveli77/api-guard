<?php

declare(strict_types=1);

namespace ApiGuard\Pro\Application;

use ApiGuard\Domain\Comparison\Change;
use ApiGuard\Domain\Comparison\Severity;

final readonly class ProComparisonResult
{
    /**
     * @param list<Change> $changes
     * @param list<IgnoredChange> $ignoredChanges
     */
    public function __construct(
        public array $changes,
        public array $ignoredChanges,
    ) {
    }

    public function hasBreakingChanges(): bool
    {
        foreach ($this->changes as $change) {
            if ($change->severity === Severity::BREAKING) {
                return true;
            }
        }

        return false;
    }
}