<?php

declare(strict_types=1);

namespace ApiGuard\Pro\Ignore;

use ApiGuard\Domain\Comparison\Change;
use ApiGuard\Domain\Comparison\ChangeType;
use InvalidArgumentException;

final readonly class IgnoreRule
{
    public function __construct(
        public ChangeType $type,
        public string $path,
        public string $reason,
        public ?string $message = null,
    ) {
        if (!str_starts_with($path, '/')) {
            throw new InvalidArgumentException('Ignore rule path must be an absolute OpenAPI path.');
        }
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Ignore rule reason cannot be empty.');
        }
        if ($message !== null && trim($message) === '') {
            throw new InvalidArgumentException('Ignore rule message cannot be empty when provided.');
        }
    }

    public function matches(Change $change): bool
    {
        return $change->type === $this->type
            && $change->path === $this->path
            && ($this->message === null || $change->message === $this->message);
    }
}