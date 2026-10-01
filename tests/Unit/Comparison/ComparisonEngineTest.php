<?php

declare(strict_types=1);

namespace ApiGuard\Tests\Unit\Comparison;

use ApiGuard\Application\ComparisonService;
use ApiGuard\Domain\Comparison\Change;
use ApiGuard\Domain\Comparison\ChangeType;
use ApiGuard\Domain\Comparison\Severity;
use ApiGuard\Domain\Specification\OpenApiSpecification;
use PHPUnit\Framework\TestCase;

final class ComparisonEngineTest extends TestCase
{
    public function testAddedAndRemovedEndpointsAreClassified(): void
    {
        $removed = $this->compare(
            ['/users' => ['get' => $this->operation()]],
            []
        );
        $this->assertSingleChange($removed, ChangeType::PATH, Severity::BREAKING);

        $added = $this->compare(
            [],
            ['/users' => ['get' => $this->operation()]]
        );
        $this->assertSingleChange($added, ChangeType::PATH, Severity::NON_BREAKING);
    }

    public function testAddedAndRemovedMethodsAreClassified(): void
    {
        $removed = $this->compare(
            ['/users' => ['get' => $this->operation(), 'post' => $this->operation()]],
            ['/users' => ['get' => $this->operation()]]
        );
        $this->assertSingleChange($removed, ChangeType::METHOD, Severity::BREAKING);

        $added = $this->compare(
            ['/users' => ['get' => $this->operation()]],
            ['/users' => ['get' => $this->operation(), 'post' => $this->operation()]]
        );
        $this->assertSingleChange($added, ChangeType::METHOD, Severity::NON_BREAKING);
    }

    public function testParameterRules(): void
    {
        $optional = ['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer']];
        $required = ['name' => 'page', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'integer']];

        $this->assertSingleChange(
            $this->compareParameterLists([], [$optional]),
            ChangeType::PARAMETER,
            Severity::NON_BREAKING
        );
        $this->assertSingleChange(
            $this->compareParameterLists([], [$required]),
            ChangeType::PARAMETER,
            Severity::BREAKING
        );
        $this->assertSingleChange(
            $this->compareParameterLists([$optional], []),
            ChangeType::PARAMETER,
            Severity::BREAKING
        );
        $this->assertSingleChange(
            $this->compareParameterLists([$optional], [$required]),
            ChangeType::PARAMETER,
            Severity::BREAKING
        );
        $this->assertSingleChange(
            $this->compareParameterLists([$required], [$optional]),
            ChangeType::PARAMETER,
            Severity::NON_BREAKING
        );
        $this->assertSingleChange(
            $this->compareParameterLists([$optional], [[...$optional, 'schema' => ['type' => 'string']]]),
            ChangeType::PARAMETER,
            Severity::BREAKING
        );
    }

    public function testOperationParametersOverridePathParameters(): void
    {
        $pathParameter = ['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer']];
        $operationParameter = ['name' => 'page', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'integer']];
        $oldPaths = ['/users' => ['parameters' => [$pathParameter], 'get' => $this->operation()]];
        $newPaths = ['/users' => ['parameters' => [$pathParameter], 'get' => $this->operation(['parameters' => [$operationParameter]])]];

        $this->assertSingleChange($this->compare($oldPaths, $newPaths), ChangeType::PARAMETER, Severity::BREAKING);
    }

    public function testRequestBodyPresenceAndRequirednessRules(): void
    {
        $emptyOperation = $this->operation();
        $requiredBody = $this->requestOperation(['type' => 'object'], true);
        $optionalBody = $this->requestOperation(['type' => 'object'], false);

        $this->assertSingleChange(
            $this->compareOperationPair($emptyOperation, $requiredBody),
            ChangeType::REQUEST_BODY,
            Severity::BREAKING
        );
        $this->assertSingleChange(
            $this->compareOperationPair($emptyOperation, $optionalBody),
            ChangeType::REQUEST_BODY,
            Severity::NON_BREAKING
        );
        $this->assertSingleChange(
            $this->compareOperationPair($requiredBody, $emptyOperation),
            ChangeType::REQUEST_BODY,
            Severity::BREAKING
        );
        $this->assertSingleChange(
            $this->compareOperationPair($this->requestOperation(['type' => 'object'], false), $requiredBody),
            ChangeType::REQUEST_BODY,
            Severity::BREAKING
        );
        $this->assertSingleChange(
            $this->compareOperationPair($requiredBody, $this->requestOperation(['type' => 'object'], false)),
            ChangeType::REQUEST_BODY,
            Severity::NON_BREAKING
        );
    }

    public function testRequestSchemaPropertyRules(): void
    {
        $base = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]];
        $optionalAdded = ['type' => 'object', 'properties' => [
            'name' => ['type' => 'string'],
            'nickname' => ['type' => 'string'],
        ]];
        $requiredAdded = [...$optionalAdded, 'required' => ['nickname']];
        $removed = ['type' => 'object', 'properties' => []];

        $this->assertSingleChange(
            $this->compareOperationPair($this->requestOperation($base), $this->requestOperation($optionalAdded)),
            ChangeType::REQUEST_BODY,
            Severity::NON_BREAKING
        );
        $this->assertSingleChange(
            $this->compareOperationPair($this->requestOperation($base), $this->requestOperation($requiredAdded)),
            ChangeType::REQUEST_BODY,
            Severity::BREAKING
        );
        $this->assertSingleChange(
            $this->compareOperationPair($this->requestOperation($base), $this->requestOperation($removed)),
            ChangeType::REQUEST_BODY,
            Severity::BREAKING
        );
        $this->assertSingleChange(
            $this->compareOperationPair(
                $this->requestOperation(['type' => 'object', 'properties' => ['count' => ['type' => 'integer']]]),
                $this->requestOperation(['type' => 'object', 'properties' => ['count' => ['type' => 'string']]])
            ),
            ChangeType::REQUEST_BODY,
            Severity::BREAKING
        );
    }

    public function testRequestPropertyCanBecomeOptionalWithoutBreakingCompatibility(): void
    {
        $required = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']];
        $optional = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]];

        $this->assertSingleChange(
            $this->compareOperationPair($this->requestOperation($required), $this->requestOperation($optional)),
            ChangeType::REQUEST_BODY,
            Severity::NON_BREAKING
        );
    }

    public function testRequestEnumNarrowingIsBreakingAndWideningIsNot(): void
    {
        $oldSchema = ['type' => 'object', 'properties' => ['state' => ['type' => 'string', 'enum' => ['draft', 'live']]]];
        $narrowSchema = ['type' => 'object', 'properties' => ['state' => ['type' => 'string', 'enum' => ['draft']]]];
        $wideSchema = ['type' => 'object', 'properties' => ['state' => ['type' => 'string', 'enum' => ['draft', 'live', 'archived']]]];

        $this->assertSingleChange(
            $this->compareOperationPair($this->requestOperation($oldSchema), $this->requestOperation($narrowSchema)),
            ChangeType::REQUEST_BODY,
            Severity::BREAKING
        );
        $this->assertSingleChange(
            $this->compareOperationPair($this->requestOperation($oldSchema), $this->requestOperation($wideSchema)),
            ChangeType::REQUEST_BODY,
            Severity::NON_BREAKING
        );
    }

    public function testNestedArrayRequestSchemaTypeChangeIsBreaking(): void
    {
        $oldSchema = ['type' => 'object', 'properties' => ['users' => [
            'type' => 'array',
            'items' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
        ]]];
        $newSchema = ['type' => 'object', 'properties' => ['users' => [
            'type' => 'array',
            'items' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
        ]]];

        $changes = $this->compareOperationPair($this->requestOperation($oldSchema), $this->requestOperation($newSchema));
        $this->assertSingleChange($changes, ChangeType::REQUEST_BODY, Severity::BREAKING);
        self::assertStringContainsString('users[].id', $changes[0]->message);
    }

    public function testRequestMediaTypeRemovalIsBreakingAndAdditionIsNot(): void
    {
        $oldOperation = $this->requestOperation(['type' => 'object']);
        $removed = $this->requestOperation(['type' => 'object'], false, []);
        $added = $this->requestOperation(['type' => 'object'], false, [
            'application/json' => ['schema' => ['type' => 'object']],
            'application/xml' => ['schema' => ['type' => 'object']],
        ]);

        $this->assertSingleChange($this->compareOperationPair($oldOperation, $removed), ChangeType::REQUEST_BODY, Severity::BREAKING);
        $this->assertSingleChange($this->compareOperationPair($oldOperation, $added), ChangeType::REQUEST_BODY, Severity::NON_BREAKING);
    }

    public function testResponsePropertyAndSchemaRules(): void
    {
        $base = ['type' => 'object', 'properties' => ['email' => ['type' => 'string']]];
        $removed = ['type' => 'object', 'properties' => []];
        $added = ['type' => 'object', 'properties' => [
            'email' => ['type' => 'string'],
            'name' => ['type' => 'string'],
        ]];

        $this->assertSingleChange(
            $this->compareOperationPair($this->responseOperation($base), $this->responseOperation($removed)),
            ChangeType::RESPONSE_SCHEMA,
            Severity::BREAKING
        );
        $this->assertSingleChange(
            $this->compareOperationPair($this->responseOperation($base), $this->responseOperation($added)),
            ChangeType::RESPONSE_SCHEMA,
            Severity::NON_BREAKING
        );
        $this->assertSingleChange(
            $this->compareOperationPair(
                $this->responseOperation(['type' => 'object', 'properties' => ['count' => ['type' => 'integer']]]),
                $this->responseOperation(['type' => 'object', 'properties' => ['count' => ['type' => 'string']]])
            ),
            ChangeType::RESPONSE_SCHEMA,
            Severity::BREAKING
        );
    }

    public function testOpenApi31TypeUnionsUseRequestAndResponseCompatibilityDirections(): void
    {
        $nullableString = ['type' => 'object', 'properties' => ['value' => ['type' => ['string', 'null']]]];
        $string = ['type' => 'object', 'properties' => ['value' => ['type' => 'string']]];

        $this->assertSingleChange(
            $this->compareOperationPair($this->responseOperation($nullableString), $this->responseOperation($string)),
            ChangeType::RESPONSE_SCHEMA,
            Severity::NON_BREAKING
        );
        $this->assertSingleChange(
            $this->compareOperationPair($this->responseOperation($string), $this->responseOperation($nullableString)),
            ChangeType::RESPONSE_SCHEMA,
            Severity::BREAKING
        );
        $this->assertSingleChange(
            $this->compareOperationPair($this->requestOperation($string), $this->requestOperation($nullableString)),
            ChangeType::REQUEST_BODY,
            Severity::NON_BREAKING
        );
        $this->assertSingleChange(
            $this->compareOperationPair($this->requestOperation($nullableString), $this->requestOperation($string)),
            ChangeType::REQUEST_BODY,
            Severity::BREAKING
        );
    }

    public function testResponseEnumAndRequirednessRules(): void
    {
        $oldSchema = ['type' => 'object', 'properties' => [
            'state' => ['type' => 'string', 'enum' => ['draft', 'live']],
            'name' => ['type' => 'string'],
        ], 'required' => ['name']];
        $enumRemoved = ['type' => 'object', 'properties' => [
            'state' => ['type' => 'string', 'enum' => ['draft']],
            'name' => ['type' => 'string'],
        ], 'required' => ['name']];
        $nameOptional = ['type' => 'object', 'properties' => [
            'state' => ['type' => 'string', 'enum' => ['draft', 'live']],
            'name' => ['type' => 'string'],
        ]];
        $nameRequired = [...$nameOptional, 'required' => ['name']];

        $this->assertSingleChange(
            $this->compareOperationPair($this->responseOperation($oldSchema), $this->responseOperation($enumRemoved)),
            ChangeType::RESPONSE_SCHEMA,
            Severity::BREAKING
        );
        $this->assertSingleChange(
            $this->compareOperationPair($this->responseOperation($oldSchema), $this->responseOperation($nameOptional)),
            ChangeType::RESPONSE_SCHEMA,
            Severity::BREAKING
        );
        self::assertSame(
            [],
            $this->compareOperationPair($this->responseOperation($nameOptional), $this->responseOperation($nameRequired))
        );

        $enumWide = ['type' => 'object', 'properties' => [
            'state' => ['type' => 'string', 'enum' => ['draft', 'live', 'archived']],
            'name' => ['type' => 'string'],
        ], 'required' => ['name']];
        $this->assertSingleChange(
            $this->compareOperationPair($this->responseOperation($oldSchema), $this->responseOperation($enumWide)),
            ChangeType::RESPONSE_SCHEMA,
            Severity::BREAKING
        );
    }

    public function testResponseStatusAndMediaRules(): void
    {
        $removedStatus = $this->operation(['responses' => []]);
        $addedStatus = $this->operation(['responses' => [
            '200' => ['description' => 'OK'],
            '201' => ['description' => 'Created'],
        ]]);
        $base = $this->responseOperation(['type' => 'object']);
        $removedMedia = $this->responseOperation(['type' => 'object'], []);
        $addedMedia = $this->responseOperation(['type' => 'object'], [
            'application/json' => ['schema' => ['type' => 'object']],
            'application/xml' => ['schema' => ['type' => 'object']],
        ]);

        $this->assertSingleChange($this->compareOperationPair($this->operation(), $removedStatus), ChangeType::STATUS, Severity::BREAKING);
        $this->assertSingleChange($this->compareOperationPair($this->operation(), $addedStatus), ChangeType::STATUS, Severity::NON_BREAKING);
        $this->assertSingleChange($this->compareOperationPair($base, $removedMedia), ChangeType::RESPONSE_SCHEMA, Severity::BREAKING);
        $this->assertSingleChange($this->compareOperationPair($base, $addedMedia), ChangeType::RESPONSE_SCHEMA, Severity::NON_BREAKING);
    }

    public function testDocumentationChangesAreNonBreakingAndUnchangedDocumentsHaveNoChanges(): void
    {
        $this->assertSingleChange(
            $this->compareOperationPair($this->operation(), $this->operation(['description' => 'A revised explanation.'])),
            ChangeType::UNKNOWN,
            Severity::NON_BREAKING
        );
        self::assertSame(
            [],
            $this->compareOperationPair($this->operation(), $this->operation())
        );
    }

    public function testRealisticCreateUserOperationDetectsNestedResponseRemoval(): void
    {
        $oldDocument = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Accounts API'],
            'paths' => ['/users' => ['post' => [
                'summary' => 'Create a user',
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => [
                    'type' => 'object',
                    'required' => ['name', 'email'],
                    'properties' => ['name' => ['type' => 'string'], 'email' => ['type' => 'string', 'format' => 'email']],
                ]]]],
                'responses' => ['201' => ['description' => 'Created', 'content' => ['application/json' => ['schema' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'profile' => ['type' => 'object', 'properties' => ['email' => ['type' => 'string']]],
                    ],
                ]]]]],
            ]]],
        ];
        $newDocument = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Accounts API'],
            'paths' => ['/users' => ['post' => [
                'summary' => 'Create a user',
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => [
                    'type' => 'object',
                    'required' => ['name', 'email'],
                    'properties' => ['name' => ['type' => 'string'], 'email' => ['type' => 'string', 'format' => 'email']],
                ]]]],
                'responses' => ['201' => ['description' => 'Created', 'content' => ['application/json' => ['schema' => [
                    'type' => 'object',
                    'properties' => ['id' => ['type' => 'integer'], 'profile' => ['type' => 'object', 'properties' => []]],
                ]]]]],
            ]]],
        ];
        $old = OpenApiSpecification::fromArray($oldDocument, 'old.yaml');
        $new = OpenApiSpecification::fromArray($newDocument, 'new.yaml');

        $changes = (new ComparisonService())->compare($old, $new);

        $this->assertSingleChange($changes, ChangeType::RESPONSE_SCHEMA, Severity::BREAKING);
        self::assertStringContainsString('profile.email', $changes[0]->message);
    }

    /**
     * @param array<string, mixed> $oldPaths
     * @param array<string, mixed> $newPaths
     * @return list<Change>
     */
    private function compare(array $oldPaths, array $newPaths): array
    {
        $old = OpenApiSpecification::fromArray(['openapi' => '3.1.0', 'info' => ['title' => 'Old'], 'paths' => $oldPaths], 'old.yaml');
        $new = OpenApiSpecification::fromArray(['openapi' => '3.1.0', 'info' => ['title' => 'New'], 'paths' => $newPaths], 'new.yaml');

        return (new ComparisonService())->compare($old, $new);
    }

    /**
     * @param array<string, mixed> $oldOperation
     * @param array<string, mixed> $newOperation
     * @return list<Change>
     */
    private function compareOperationPair(array $oldOperation, array $newOperation): array
    {
        return $this->compare(['/users' => ['post' => $oldOperation]], ['/users' => ['post' => $newOperation]]);
    }

    /**
     * @param list<array<string, mixed>> $oldParameters
     * @param list<array<string, mixed>> $newParameters
     * @return list<Change>
     */
    private function compareParameterLists(array $oldParameters, array $newParameters): array
    {
        return $this->compareOperationPair(
            $this->operation(['parameters' => $oldParameters]),
            $this->operation(['parameters' => $newParameters])
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function operation(array $overrides = []): array
    {
        return array_merge(['responses' => ['200' => ['description' => 'OK']]], $overrides);
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed>|null $content
     * @return array<string, mixed>
     */
    private function requestOperation(array $schema, bool $required = false, ?array $content = null): array
    {
        return $this->operation(['requestBody' => [
            'required' => $required,
            'content' => $content ?? ['application/json' => ['schema' => $schema]],
        ]]);
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed>|null $content
     * @return array<string, mixed>
     */
    private function responseOperation(array $schema, ?array $content = null): array
    {
        return $this->operation(['responses' => ['200' => [
            'description' => 'OK',
            'content' => $content ?? ['application/json' => ['schema' => $schema]],
        ]]]);
    }

    /**
     * @param list<Change> $changes
     */
    private function assertSingleChange(array $changes, ChangeType $type, Severity $severity): void
    {
        self::assertCount(1, $changes);
        self::assertSame($type, $changes[0]->type);
        self::assertSame($severity, $changes[0]->severity);
        self::assertSame('/users', $changes[0]->path);
    }
}