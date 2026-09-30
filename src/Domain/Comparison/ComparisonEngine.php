<?php

declare(strict_types=1);

namespace ApiGuard\Domain\Comparison;

use ApiGuard\Domain\Specification\OpenApiSpecification;

final class ComparisonEngine
{
    private const METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    /**
     * @return list<Change>
     */
    public function compare(OpenApiSpecification $oldVersion, OpenApiSpecification $newVersion): array
    {
        $changes = [];
        $oldPaths = $this->resolvePathReferences($oldVersion->paths, $oldVersion->components);
        $newPaths = $this->resolvePathReferences($newVersion->paths, $newVersion->components);

        foreach ($oldPaths as $path => $oldPathItem) {
            $newPathItem = $newPaths[$path] ?? null;

            if ($newPathItem === null) {
                $changes[] = new Change(ChangeType::PATH, Severity::BREAKING, $path, 'Endpoint was removed.');
                continue;
            }

            foreach (self::METHODS as $method) {
                $oldOperation = $this->stringKeyedMap($oldPathItem[$method] ?? null);
                $newOperation = $this->stringKeyedMap($newPathItem[$method] ?? null);

                if ($oldOperation !== null && $newOperation === null) {
                    $changes[] = new Change(ChangeType::METHOD, Severity::BREAKING, $path, strtoupper($method) . ' method was removed.');
                    continue;
                }

                if ($oldOperation === null || $newOperation === null) {
                    continue;
                }

                $this->compareOperation($path, $method, $oldPathItem, $newPathItem, $oldOperation, $newOperation, $changes);
            }
        }

        foreach ($newPaths as $path => $newPathItem) {
            if (!array_key_exists($path, $oldPaths)) {
                $changes[] = new Change(ChangeType::PATH, Severity::NON_BREAKING, $path, 'Endpoint was added.');
                continue;
            }

            $oldPathItem = $oldPaths[$path];
            foreach (self::METHODS as $method) {
                if (!is_array($oldPathItem[$method] ?? null) && is_array($newPathItem[$method] ?? null)) {
                    $changes[] = new Change(ChangeType::METHOD, Severity::NON_BREAKING, $path, strtoupper($method) . ' method was added.');
                }
            }
        }

        $severityOrder = [
            Severity::BREAKING->value => 0,
            Severity::NON_BREAKING->value => 1,
            Severity::INFO->value => 2,
        ];
        usort($changes, static fn (Change $left, Change $right): int => [
            $severityOrder[$left->severity->value],
            $left->path,
            $left->type->value,
            $left->message,
        ] <=> [
            $severityOrder[$right->severity->value],
            $right->path,
            $right->type->value,
            $right->message,
        ]);

        return $changes;
    }

    /**
     * @param array<string, mixed> $oldPathItem
     * @param array<string, mixed> $newPathItem
     * @param array<string, mixed> $oldOperation
     * @param array<string, mixed> $newOperation
     * @param list<Change> $changes
     */
    private function compareOperation(
        string $path,
        string $method,
        array $oldPathItem,
        array $newPathItem,
        array $oldOperation,
        array $newOperation,
        array &$changes,
    ): void {
        foreach (['summary', 'description'] as $field) {
            if (($oldOperation[$field] ?? null) !== ($newOperation[$field] ?? null)) {
                $changes[] = new Change(ChangeType::UNKNOWN, Severity::NON_BREAKING, $path, strtoupper($method) . ' operation ' . $field . ' changed.');
            }
        }

        $this->compareParameters(
            $path,
            $method,
            $this->effectiveParameters($oldPathItem, $oldOperation),
            $this->effectiveParameters($newPathItem, $newOperation),
            $changes,
        );
        $this->compareRequestBody($path, $method, $oldOperation, $newOperation, $changes);
        $this->compareResponses($path, $method, $oldOperation, $newOperation, $changes);
    }

    /**
     * @param array<string, mixed> $pathItem
     * @param array<string, mixed> $operation
     * @return array<string, array<string, mixed>>
     */
    private function effectiveParameters(array $pathItem, array $operation): array
    {
        $parameters = [];

        foreach ([$pathItem['parameters'] ?? [], $operation['parameters'] ?? []] as $parameterList) {
            if (!is_array($parameterList)) {
                continue;
            }

            foreach ($parameterList as $parameter) {
                $parameter = $this->stringKeyedMap($parameter);
                if ($parameter === null || !is_string($parameter['name'] ?? null) || !is_string($parameter['in'] ?? null)) {
                    continue;
                }

                $parameters[$parameter['in'] . ':' . $parameter['name']] = $parameter;
            }
        }

        return $parameters;
    }

    /**
     * @param array<string, array<string, mixed>> $oldParameters
     * @param array<string, array<string, mixed>> $newParameters
     * @param list<Change> $changes
     */
    private function compareParameters(string $path, string $method, array $oldParameters, array $newParameters, array &$changes): void
    {
        foreach ($oldParameters as $key => $oldParameter) {
            $newParameter = $newParameters[$key] ?? null;
            $parameterIn = is_string($oldParameter['in'] ?? null) ? $oldParameter['in'] : '';
            $parameterName = is_string($oldParameter['name'] ?? null) ? $oldParameter['name'] : '';
            $label = strtoupper($method) . ' parameter ' . $parameterIn . '.' . $parameterName;

            if ($newParameter === null) {
                $changes[] = new Change(ChangeType::PARAMETER, Severity::BREAKING, $path, $label . ' was removed.');
                continue;
            }

            $oldRequired = ($oldParameter['required'] ?? false) === true;
            $newRequired = ($newParameter['required'] ?? false) === true;
            if (!$oldRequired && $newRequired) {
                $changes[] = new Change(ChangeType::PARAMETER, Severity::BREAKING, $path, $label . ' became required.');
            } elseif ($oldRequired && !$newRequired) {
                $changes[] = new Change(ChangeType::PARAMETER, Severity::NON_BREAKING, $path, $label . ' became optional.');
            }

            $oldSchema = $this->stringKeyedMap($oldParameter['schema'] ?? null) ?? [];
            $newSchema = $this->stringKeyedMap($newParameter['schema'] ?? null) ?? [];
            $this->compareSchema($path, $label, $oldSchema, $newSchema, false, ChangeType::PARAMETER, $changes);
        }

        foreach ($newParameters as $key => $parameter) {
            if (array_key_exists($key, $oldParameters)) {
                continue;
            }

            $parameterIn = is_string($parameter['in'] ?? null) ? $parameter['in'] : '';
            $parameterName = is_string($parameter['name'] ?? null) ? $parameter['name'] : '';
            $label = strtoupper($method) . ' parameter ' . $parameterIn . '.' . $parameterName;
            $required = ($parameter['required'] ?? false) === true;
            $severity = $required ? Severity::BREAKING : Severity::NON_BREAKING;
            $description = $required ? ' was added as required.' : ' was added as optional.';
            $changes[] = new Change(ChangeType::PARAMETER, $severity, $path, $label . $description);
        }
    }

    /**
     * @param array<string, mixed> $oldOperation
     * @param array<string, mixed> $newOperation
     * @param list<Change> $changes
     */
    private function compareRequestBody(string $path, string $method, array $oldOperation, array $newOperation, array &$changes): void
    {
        $oldBody = $this->stringKeyedMap($oldOperation['requestBody'] ?? null);
        $newBody = $this->stringKeyedMap($newOperation['requestBody'] ?? null);
        $label = strtoupper($method) . ' request body';

        if ($oldBody === null) {
            if ($newBody === null) {
                return;
            }

            $required = ($newBody['required'] ?? false) === true;
            $changes[] = new Change(
                ChangeType::REQUEST_BODY,
                $required ? Severity::BREAKING : Severity::NON_BREAKING,
                $path,
                $required ? $label . ' was introduced as required.' : $label . ' was introduced as optional.'
            );
            return;
        }

        if ($newBody === null) {
            $changes[] = new Change(ChangeType::REQUEST_BODY, Severity::BREAKING, $path, $label . ' was removed.');
            return;
        }

        $oldRequired = ($oldBody['required'] ?? false) === true;
        $newRequired = ($newBody['required'] ?? false) === true;
        if (!$oldRequired && $newRequired) {
            $changes[] = new Change(ChangeType::REQUEST_BODY, Severity::BREAKING, $path, $label . ' became required.');
        } elseif ($oldRequired && !$newRequired) {
            $changes[] = new Change(ChangeType::REQUEST_BODY, Severity::NON_BREAKING, $path, $label . ' became optional.');
        }

        $this->compareContent($path, $label, $oldBody['content'] ?? [], $newBody['content'] ?? [], false, ChangeType::REQUEST_BODY, $changes);
    }

    /**
     * @param array<string, mixed> $oldOperation
     * @param array<string, mixed> $newOperation
     * @param list<Change> $changes
     */
    private function compareResponses(string $path, string $method, array $oldOperation, array $newOperation, array &$changes): void
    {
        $oldResponses = is_array($oldOperation['responses'] ?? null) ? $oldOperation['responses'] : [];
        $newResponses = is_array($newOperation['responses'] ?? null) ? $newOperation['responses'] : [];

        foreach ($oldResponses as $status => $oldResponse) {
            if (!is_array($oldResponse)) {
                continue;
            }

            $newResponse = $this->stringKeyedMap($newResponses[$status] ?? null);
            if ($newResponse === null) {
                $changes[] = new Change(ChangeType::STATUS, Severity::BREAKING, $path, strtoupper($method) . ' response status ' . (string) $status . ' was removed.');
                continue;
            }

            $label = strtoupper($method) . ' response ' . $status;
            $this->compareContent($path, $label, $oldResponse['content'] ?? [], $newResponse['content'] ?? [], true, ChangeType::RESPONSE_SCHEMA, $changes);
        }

        foreach ($newResponses as $status => $newResponse) {
            if (!array_key_exists($status, $oldResponses) && is_array($newResponse)) {
                $changes[] = new Change(ChangeType::STATUS, Severity::NON_BREAKING, $path, strtoupper($method) . ' response status ' . (string) $status . ' was added.');
            }
        }
    }

    /**
     * @param mixed $oldContent
     * @param mixed $newContent
     * @param list<Change> $changes
     */
    private function compareContent(
        string $path,
        string $label,
        mixed $oldContent,
        mixed $newContent,
        bool $response,
        ChangeType $changeType,
        array &$changes,
    ): void {
        $oldContent = is_array($oldContent) ? $oldContent : [];
        $newContent = is_array($newContent) ? $newContent : [];

        foreach ($oldContent as $mediaType => $oldMedia) {
            $oldMedia = $this->stringKeyedMap($oldMedia);
            if (!is_string($mediaType) || $oldMedia === null) {
                continue;
            }

            $newMedia = $this->stringKeyedMap($newContent[$mediaType] ?? null);
            if ($newMedia === null) {
                $changes[] = new Change($changeType, Severity::BREAKING, $path, $label . ' media type ' . $mediaType . ' was removed.');
                continue;
            }

            $oldSchema = $this->stringKeyedMap($oldMedia['schema'] ?? null) ?? [];
            $newSchema = $this->stringKeyedMap($newMedia['schema'] ?? null) ?? [];
            $this->compareSchema($path, $label . ' ' . $mediaType, $oldSchema, $newSchema, $response, $changeType, $changes);
        }

        foreach ($newContent as $mediaType => $newMedia) {
            if (is_string($mediaType) && !array_key_exists($mediaType, $oldContent) && is_array($newMedia)) {
                $changes[] = new Change($changeType, Severity::NON_BREAKING, $path, $label . ' media type ' . $mediaType . ' was added.');
            }
        }
    }

    /**
     * @param array<string, mixed> $oldSchema
     * @param array<string, mixed> $newSchema
     * @param list<Change> $changes
     */
    private function compareSchema(
        string $path,
        string $label,
        array $oldSchema,
        array $newSchema,
        bool $response,
        ChangeType $changeType,
        array &$changes,
        string $propertyPath = '',
    ): void {
        $schemaLabel = $label . ($propertyPath === '' ? '' : ' property ' . $propertyPath);
        $oldType = $oldSchema['type'] ?? null;
        $newType = $newSchema['type'] ?? null;
        if ($oldType !== $newType && (is_string($oldType) || is_string($newType))) {
            $severity = Severity::BREAKING;
            if (($response && $oldType === null) || (!$response && $newType === null)) {
                $severity = Severity::NON_BREAKING;
            }
            $oldTypeLabel = is_string($oldType) ? $oldType : 'unconstrained';
            $newTypeLabel = is_string($newType) ? $newType : 'unconstrained';
            $changes[] = new Change($changeType, $severity, $path, $schemaLabel . ' changed type from ' . $oldTypeLabel . ' to ' . $newTypeLabel . '.');
        }

        $oldEnum = is_array($oldSchema['enum'] ?? null) ? $oldSchema['enum'] : null;
        $newEnum = is_array($newSchema['enum'] ?? null) ? $newSchema['enum'] : null;
        if ($oldEnum !== null && $newEnum !== null) {
            foreach ($oldEnum as $value) {
                if (!in_array($value, $newEnum, true)) {
                    $encodedValue = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $changes[] = new Change($changeType, Severity::BREAKING, $path, $schemaLabel . ' enum value ' . ($encodedValue === false ? '[unknown]' : $encodedValue) . ' was removed.');
                }
            }
            foreach ($newEnum as $value) {
                if (!in_array($value, $oldEnum, true)) {
                    $encodedValue = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $severity = $response ? Severity::BREAKING : Severity::NON_BREAKING;
                    $changes[] = new Change($changeType, $severity, $path, $schemaLabel . ' enum value ' . ($encodedValue === false ? '[unknown]' : $encodedValue) . ' was added.');
                }
            }
        }

        $oldNullable = ($oldSchema['nullable'] ?? false) === true;
        $newNullable = ($newSchema['nullable'] ?? false) === true;
        if ($oldNullable !== $newNullable) {
            $severity = $response
                ? ($newNullable ? Severity::BREAKING : Severity::NON_BREAKING)
                : ($newNullable ? Severity::NON_BREAKING : Severity::BREAKING);
            $changes[] = new Change(
                $changeType,
                $severity,
                $path,
                $schemaLabel . ' changed nullable from ' . ($oldNullable ? 'true' : 'false') . ' to ' . ($newNullable ? 'true' : 'false') . '.'
            );
        }

        $oldProperties = is_array($oldSchema['properties'] ?? null) ? $oldSchema['properties'] : [];
        $newProperties = is_array($newSchema['properties'] ?? null) ? $newSchema['properties'] : [];
        $oldRequired = is_array($oldSchema['required'] ?? null) ? $oldSchema['required'] : [];
        $newRequired = is_array($newSchema['required'] ?? null) ? $newSchema['required'] : [];

        foreach ($oldRequired as $requiredProperty) {
            if (is_string($requiredProperty)
                && array_key_exists($requiredProperty, $oldProperties)
                && array_key_exists($requiredProperty, $newProperties)
                && !in_array($requiredProperty, $newRequired, true)
            ) {
                $requiredPath = $propertyPath === '' ? $requiredProperty : $propertyPath . '.' . $requiredProperty;
                $severity = $response ? Severity::BREAKING : Severity::NON_BREAKING;
                $changes[] = new Change($changeType, $severity, $path, $label . ' property ' . $requiredPath . ' became optional.');
            }
        }
        foreach ($newRequired as $requiredProperty) {
            if (is_string($requiredProperty)
                && array_key_exists($requiredProperty, $oldProperties)
                && array_key_exists($requiredProperty, $newProperties)
                && !in_array($requiredProperty, $oldRequired, true)
                && !$response
            ) {
                $requiredPath = $propertyPath === '' ? $requiredProperty : $propertyPath . '.' . $requiredProperty;
                $changes[] = new Change($changeType, Severity::BREAKING, $path, $label . ' property ' . $requiredPath . ' became required.');
            }
        }

        foreach ($oldProperties as $property => $oldPropertySchema) {
            $oldPropertySchema = $this->stringKeyedMap($oldPropertySchema);
            if (!is_string($property) || $oldPropertySchema === null) {
                continue;
            }

            $nestedPath = $propertyPath === '' ? $property : $propertyPath . '.' . $property;
            $newPropertySchema = $this->stringKeyedMap($newProperties[$property] ?? null);
            if ($newPropertySchema === null) {
                $fullPropertyPath = $propertyPath === '' ? $property : $propertyPath . '.' . $property;
                if ($response) {
                    $changes[] = new Change($changeType, Severity::BREAKING, $path, $label . ' property ' . $fullPropertyPath . ' was removed.');
                } else {
                    $changes[] = new Change($changeType, Severity::BREAKING, $path, $label . ' request property ' . $fullPropertyPath . ' was removed.');
                }
                continue;
            }

            $this->compareSchema($path, $label, $oldPropertySchema, $newPropertySchema, $response, $changeType, $changes, $nestedPath);
        }

        foreach ($newProperties as $property => $newPropertySchema) {
            $newPropertySchema = $this->stringKeyedMap($newPropertySchema);
            if (!is_string($property) || array_key_exists($property, $oldProperties) || $newPropertySchema === null) {
                continue;
            }

            if (!$response && in_array($property, $newRequired, true)) {
                $fullPropertyPath = $propertyPath === '' ? $property : $propertyPath . '.' . $property;
                $changes[] = new Change($changeType, Severity::BREAKING, $path, $label . ' required property ' . $fullPropertyPath . ' was added.');
            } else {
                $propertyLabel = !$response || !in_array($property, $newRequired, true) ? ' optional' : '';
                $fullPropertyPath = $propertyPath === '' ? $property : $propertyPath . '.' . $property;
                $changes[] = new Change($changeType, Severity::NON_BREAKING, $path, $label . $propertyLabel . ' property ' . $fullPropertyPath . ' was added.');
            }
        }

        $oldItems = $this->stringKeyedMap($oldSchema['items'] ?? null);
        $newItems = $this->stringKeyedMap($newSchema['items'] ?? null);
        if ($oldItems !== null && $newItems !== null) {
            $this->compareSchema($path, $label, $oldItems, $newItems, $response, $changeType, $changes, $propertyPath . '[]');
        }

        $oldAdditionalProperties = $this->stringKeyedMap($oldSchema['additionalProperties'] ?? null);
        $newAdditionalProperties = $this->stringKeyedMap($newSchema['additionalProperties'] ?? null);
        if ($oldAdditionalProperties !== null && $newAdditionalProperties !== null) {
            $this->compareSchema($path, $label, $oldAdditionalProperties, $newAdditionalProperties, $response, $changeType, $changes, $propertyPath . '{*}');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function stringKeyedMap(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $map = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                return null;
            }

            $map[$key] = $item;
        }

        return $map;
    }

    /**
     * @param array<string, array<string, mixed>> $paths
     * @param array<string, mixed> $components
     * @return array<string, array<string, mixed>>
     */
    private function resolvePathReferences(array $paths, array $components): array
    {
        $resolvedPaths = [];
        foreach ($paths as $path => $pathItem) {
            $resolvedPathItem = $this->stringKeyedMap($this->resolveSchemaReferences($pathItem, $components));
            if ($resolvedPathItem !== null) {
                $resolvedPaths[$path] = $resolvedPathItem;
            }
        }

        return $resolvedPaths;
    }

    /**
     * @param array<string, mixed> $components
     * @param list<string> $referenceStack
     */
    private function resolveSchemaReferences(mixed $value, array $components, array $referenceStack = []): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $reference = $value['$ref'] ?? null;
        if (is_string($reference) && str_starts_with($reference, '#/components/schemas/')) {
            if (in_array($reference, $referenceStack, true)) {
                return $value;
            }

            $target = $this->resolveComponentSchema($reference, $components);
            if ($target !== null) {
                $siblings = $value;
                unset($siblings['$ref']);
                $referenceStack = [...$referenceStack, $reference];
                $resolvedTarget = $this->resolveSchemaReferences($target, $components, $referenceStack);
                if (is_array($resolvedTarget)) {
                    $value = array_replace($resolvedTarget, $siblings);
                }
            }
        }

        foreach ($value as $key => $nestedValue) {
            $value[$key] = $this->resolveSchemaReferences($nestedValue, $components, $referenceStack);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $components
     * @return array<string, mixed>|null
     */
    private function resolveComponentSchema(string $reference, array $components): ?array
    {
        $tokens = explode('/', substr($reference, 2));
        array_shift($tokens);
        $value = $components;

        foreach ($tokens as $token) {
            $key = str_replace(['~1', '~0'], ['/', '~'], $token);
            if (!array_key_exists($key, $value) || !is_array($value[$key])) {
                return null;
            }

            $value = $value[$key];
        }

        return $this->stringKeyedMap($value);
    }
}