<?php

declare(strict_types=1);

namespace ApiGuard\Infrastructure\OpenApi;

use ApiGuard\Domain\Specification\OpenApiSpecification;
use JsonException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class OpenApiFileLoader implements SpecificationLoaderInterface
{
    private const HTTP_METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

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
        if (!is_string($document['info']['title'] ?? null) || !is_string($document['info']['version'] ?? null)) {
            throw new SpecificationLoadException(sprintf('OpenAPI specification %s info object must define string title and version values.', $path));
        }

        if (!array_key_exists('paths', $document) || !is_array($document['paths'])) {
            throw new SpecificationLoadException(sprintf('OpenAPI specification %s is missing the required paths object.', $path));
        }

        foreach ($document['paths'] as $pathName => $pathItem) {
            if (!is_string($pathName) || !is_array($pathItem)) {
                throw new SpecificationLoadException(sprintf('OpenAPI specification %s contains an invalid path entry.', $path));
            }

            foreach ($pathItem as $method => $operation) {
                if (!is_string($method) || !in_array($method, self::HTTP_METHODS, true)) {
                    continue;
                }

                if (!is_array($operation)) {
                    throw new SpecificationLoadException(sprintf('OpenAPI specification %s contains an invalid %s operation at %s.', $path, strtoupper($method), $pathName));
                }

                $responses = $operation['responses'] ?? null;
                if (!is_array($responses) || $responses === [] || array_is_list($responses)) {
                    throw new SpecificationLoadException(sprintf('OpenAPI specification %s %s operation at %s must define a non-empty responses object.', $path, strtoupper($method), $pathName));
                }

                foreach ($responses as $status => $response) {
                    $this->validateResponseEntry($response, $path, $pathName, $method, (string) $status);
                }
            }
        }

        return $document;
    }

    private function validateResponseEntry(mixed $response, string $specificationPath, string $pathName, string $method, string $status): void
    {
        $label = sprintf('%s %s operation at %s response %s', strtoupper($method), $specificationPath, $pathName, $status);

        $response = $this->mapping($response);
        if ($response === null) {
            throw new SpecificationLoadException(sprintf('OpenAPI specification %s contains an invalid response object at %s.', $specificationPath, $label));
        }

        if (array_key_exists('$ref', $response)) {
            if (!is_string($response['$ref']) || trim($response['$ref']) === '') {
                throw new SpecificationLoadException(sprintf('OpenAPI specification %s contains an invalid response reference at %s.', $specificationPath, $label));
            }

            return;
        }

        if (!is_string($response['description'] ?? null)) {
            throw new SpecificationLoadException(sprintf('OpenAPI specification %s response object at %s must define a string description.', $specificationPath, $label));
        }

        foreach (['headers', 'content', 'links'] as $field) {
            if (!array_key_exists($field, $response)) {
                continue;
            }

            $entries = $response[$field];
            $entries = $this->mapping($entries);
            if ($entries === null) {
                throw new SpecificationLoadException(sprintf('OpenAPI specification %s response %s field "%s" must be an object.', $specificationPath, $label, $field));
            }

            foreach ($entries as $name => $entry) {
                if (!is_string($name) || $this->mapping($entry) === null) {
                    throw new SpecificationLoadException(sprintf('OpenAPI specification %s response %s field "%s" contains an invalid object entry.', $specificationPath, $label, $field));
                }
            }
        }
    }

    /**
     * @return array<mixed>|null
     */
    private function mapping(mixed $value): ?array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            return null;
        }

        return $value;
    }
}
