<?php

declare(strict_types=1);

namespace ApiGuard\Pro\Application;

use ApiGuard\Application\ComparisonService;
use ApiGuard\Infrastructure\OpenApi\SpecificationLoaderInterface;
use ApiGuard\Pro\Ignore\IgnoreConfigurationLoader;

final readonly class ProComparisonService
{
    public function __construct(
        private SpecificationLoaderInterface $specificationLoader,
        private ComparisonService $comparisonService,
        private IgnoreConfigurationLoader $ignoreConfigurationLoader,
    ) {
    }

    public function compareFiles(string $oldPath, string $newPath, ?string $ignoreConfigPath = null): ProComparisonResult
    {
        $policy = $this->ignoreConfigurationLoader->load($ignoreConfigPath);
        $oldSpecification = $this->specificationLoader->load($oldPath);
        $newSpecification = $this->specificationLoader->load($newPath);
        $changes = $this->comparisonService->compare($oldSpecification, $newSpecification);

        $visibleChanges = [];
        $ignoredChanges = [];
        foreach ($changes as $change) {
            $rule = $policy->matchingRule($change);
            if ($rule === null) {
                $visibleChanges[] = $change;
                continue;
            }

            $ignoredChanges[] = new IgnoredChange($change, $rule->reason);
        }

        return new ProComparisonResult($visibleChanges, $ignoredChanges);
    }
}