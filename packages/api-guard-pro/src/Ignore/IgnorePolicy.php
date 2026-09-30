<?php

declare(strict_types=1);

namespace ApiGuard\Pro\Ignore;

use ApiGuard\Domain\Comparison\Change;

final readonly class IgnorePolicy
{
    /**
     * @param list<IgnoreRule> $rules
     */
    public function __construct(public array $rules = [])
    {
    }

    public function matchingRule(Change $change): ?IgnoreRule
    {
        foreach ($this->rules as $rule) {
            if ($rule->matches($change)) {
                return $rule;
            }
        }

        return null;
    }
}