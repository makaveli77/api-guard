<?php

declare(strict_types=1);

namespace ApiGuard\Domain\Specification;

final readonly class OpenApiSpecification
{
    public function __construct(
        public string $version,
        public string $sourcePath,
    ) {
    }
}
