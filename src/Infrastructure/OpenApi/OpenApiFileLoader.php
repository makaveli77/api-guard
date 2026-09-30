<?php

declare(strict_types=1);

namespace ApiGuard\Infrastructure\OpenApi;

use ApiGuard\Domain\Specification\OpenApiSpecification;
use JsonException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class OpenApiFileLoader implements SpecificationLoaderInterface
{
    public function load(string $path): OpenApiSpecification
    {
        if (trim($path) === '') {
            throw new SpecificationLoadException('OpenAPI specification path cannot be empty.');
        }

        if (!file_exists($path)) {
            throw new SpecificationLoadException(sprintf('OpenAPI specification file not found: %s', $path));
        }

        if (!is_readable($path)) {
            throw new SpecificationLoadException(sprintf('OpenAPI specification file is not readable: %s', $path));
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new SpecificationLoadException(sprintf('Unable to read OpenAPI specification: %s', $path));
        }

        $document = $this->parseDocument($path, $contents);

        return OpenApiSpecification::fromArray($this->validateDocument($document, $path), $path);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseDocument(string $path, string $contents): array
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'json') {
            return $this->parseJsonDocument($contents, $path);
        }

        if (in_array($extension, ['yaml', 'yml'], true)) {
            return $this->parseYamlDocument($contents, $path);
        }

        try {
            return $this->parseJsonDocument($contents, $path);
        } catch (SpecificationLoadException) {
            return $this->parseYamlDocument($contents, $path);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function parseJsonDocument(string $contents, string $path): array
    {
        try {
            $document = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new SpecificationLoadException(sprintf('Invalid JSON in OpenAPI specification %s: %s', $path, $exception->getMessage()), 0, $exception);
        }

        if (!is_array($document)) {
            throw new SpecificationLoadException(sprintf('OpenAPI specification %s must decode to a JSON object.', $path));
        }

        /** @var array<string, mixed> $document */
        return $document;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseYamlDocument(string $contents, string $path): array
    {
        if (trim($contents) === '') {
            throw new SpecificationLoadException(sprintf('OpenAPI specification %s is empty.', $path));
        }

        try {
            $document = Yaml::parse($contents);
        } catch (ParseException $exception) {
            throw new SpecificationLoadException(sprintf('Invalid YAML in OpenAPI specification %s: %s', $path, $exception->getMessage()), 0, $exception);
        }

        if (!is_array($document)) {
            throw new SpecificationLoadException(sprintf('OpenAPI specification %s must decode to a YAML mapping.', $path));
        }

        /** @var array<string, mixed> $document */
        return $document;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function validateDocument(array $document, string $path): array
    {
        if (!array_key_exists('openapi', $document) || !is_string($document['openapi']) || !preg_match('/^3\./', $document['openapi'])) {
            throw new SpecificationLoadException(sprintf('OpenAPI specification %s is not a valid OpenAPI 3.x document.', $path));
        }

        if (!array_key_exists('info', $document) || !is_array($document['info'])) {
            throw new SpecificationLoadException(sprintf('OpenAPI specification %s is missing the required info object.', $path));
        }

        if (!array_key_exists('paths', $document) || !is_array($document['paths'])) {
            throw new SpecificationLoadException(sprintf('OpenAPI specification %s is missing the required paths object.', $path));
        }

        foreach ($document['paths'] as $pathName => $pathItem) {
            if (!is_string($pathName) || !is_array($pathItem)) {
                throw new SpecificationLoadException(sprintf('OpenAPI specification %s contains an invalid path entry.', $path));
            }
        }

        return $document;
    }
}
