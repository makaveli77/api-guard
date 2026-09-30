<?php

declare(strict_types=1);

namespace ApiGuard\Domain\Specification;

final readonly class OpenApiSpecification
{
    /**
     * @param array<string, array<string, mixed>> $paths
     */
    public function __construct(
        public string $version,
        public string $sourcePath,
        public string $title,
        public array $paths,
    ) {
    }

    /**
     * @param array<string, mixed> $document
     */
    public static function fromArray(array $document, string $sourcePath): self
    {
        $version = is_string($document['openapi'] ?? null) ? $document['openapi'] : '';
        $info = is_array($document['info'] ?? null) ? $document['info'] : [];
        $title = is_string($info['title'] ?? null) ? $info['title'] : 'Untitled API';
        $paths = is_array($document['paths'] ?? null) ? $document['paths'] : [];

        /** @var array<string, array<string, mixed>> $paths */
        $paths = $paths;

        return new self($version, $sourcePath, $title, $paths);
    }

    public function hasPath(string $pathName): bool
    {
        return array_key_exists($pathName, $this->paths);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPath(string $pathName): ?array
    {
        return $this->paths[$pathName] ?? null;
    }
}
