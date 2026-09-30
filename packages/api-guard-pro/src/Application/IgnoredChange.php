<?php

declare(strict_types=1);

namespace ApiGuard\Pro\Application;

use ApiGuard\Domain\Comparison\Change;

final readonly class IgnoredChange
{
    public function __construct(
        public Change $change,
        public string $reason,
    ) {
    }
}