<?php

declare(strict_types=1);

namespace ApiGuard\Domain\Comparison;

final readonly class Change
{
    public function __construct(
        public ChangeType $type,
        public Severity $severity,
        public string $path,
        public string $message,
    ) {
    }
}
