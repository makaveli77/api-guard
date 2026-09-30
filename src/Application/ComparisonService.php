<?php

declare(strict_types=1);

namespace ApiGuard\Application;

use ApiGuard\Domain\Comparison\Change;
use ApiGuard\Domain\Comparison\ComparisonEngine;
use ApiGuard\Domain\Specification\OpenApiSpecification;

final class ComparisonService
{
    /**
     * @return list<Change>
     */
    public function compare(OpenApiSpecification $oldVersion, OpenApiSpecification $newVersion): array
    {
        return (new ComparisonEngine())->compare($oldVersion, $newVersion);
    }
}
