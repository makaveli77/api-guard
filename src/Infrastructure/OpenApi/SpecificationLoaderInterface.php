<?php

declare(strict_types=1);

namespace ApiGuard\Infrastructure\OpenApi;

use ApiGuard\Domain\Specification\OpenApiSpecification;

interface SpecificationLoaderInterface
{
    public function load(string $path): OpenApiSpecification;
}
