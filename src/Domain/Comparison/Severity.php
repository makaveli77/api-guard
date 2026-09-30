<?php

declare(strict_types=1);

namespace ApiGuard\Domain\Comparison;

enum Severity: string
{
    case BREAKING = 'breaking';
    case NON_BREAKING = 'non_breaking';
    case INFO = 'info';
}
