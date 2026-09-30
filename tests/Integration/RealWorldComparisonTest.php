<?php

declare(strict_types=1);

namespace ApiGuard\Tests\Integration;

use ApiGuard\Application\ComparisonService;
use ApiGuard\Domain\Comparison\Change;
use ApiGuard\Domain\Comparison\ChangeType;
use ApiGuard\Domain\Comparison\Severity;
use ApiGuard\Domain\Specification\OpenApiSpecification;
use ApiGuard\Infrastructure\OpenApi\OpenApiFileLoader;
use PHPUnit\Framework\TestCase;

final class RealWorldComparisonTest extends TestCase
{
    public function testProductionStyleYamlAndJsonSpecificationsReportCombinedChanges(): void
    {
        $fixtureDirectory = dirname(__DIR__) . '/Fixtures/RealWorld';
        $loader = new OpenApiFileLoader();
        $oldSpecification = $loader->load($fixtureDirectory . '/openapi-v1.yaml');
        $newSpecification = $loader->load($fixtureDirectory . '/openapi-v2.json');

        $changes = (new ComparisonService())->compare($oldSpecification, $newSpecification);

        $this->assertChange($changes, ChangeType::PARAMETER, Severity::BREAKING, '/users/{userId}', 'parameter path.userId changed type');
        $this->assertChange($changes, ChangeType::PARAMETER, Severity::BREAKING, '/users/{userId}', 'parameter query.include became required');
        $this->assertChange($changes, ChangeType::PARAMETER, Severity::BREAKING, '/users/{userId}', 'parameter header.X-Request-ID changed type');
        $this->assertChange($changes, ChangeType::PARAMETER, Severity::BREAKING, '/users/{userId}', 'parameter cookie.session was removed');
        $this->assertChange($changes, ChangeType::REQUEST_BODY, Severity::BREAKING, '/users/{userId}', 'required property accountType was added');
        $this->assertChange($changes, ChangeType::REQUEST_BODY, Severity::BREAKING, '/users/{userId}', 'enum value "sms" was removed');
        $this->assertChange($changes, ChangeType::REQUEST_BODY, Severity::BREAKING, '/users/{userId}', 'property preferences.marketingEmails changed nullable from true to false');
        $this->assertChange($changes, ChangeType::RESPONSE_SCHEMA, Severity::BREAKING, '/users/{userId}', 'property email was removed');
        $this->assertChange($changes, ChangeType::RESPONSE_SCHEMA, Severity::NON_BREAKING, '/users/{userId}', 'property nickname was added');
        $this->assertChange($changes, ChangeType::RESPONSE_SCHEMA, Severity::NON_BREAKING, '/users/{userId}', 'property profile.timezone changed nullable from true to false');
        $this->assertChange($changes, ChangeType::RESPONSE_SCHEMA, Severity::BREAKING, '/orders', 'property [].items[].quantity changed type from integer to string');
        $this->assertChange($changes, ChangeType::STATUS, Severity::BREAKING, '/orders', 'response status 404 was removed');
        $this->assertChange($changes, ChangeType::PATH, Severity::NON_BREAKING, '/health', 'Endpoint was added');
        $this->assertChange($changes, ChangeType::METHOD, Severity::NON_BREAKING, '/users/{userId}', 'PATCH method was added');
    }

    public function testComparisonsAreDeterministicAcrossRepeatedRuns(): void
    {
        $fixtureDirectory = dirname(__DIR__) . '/Fixtures/RealWorld';
        $loader = new OpenApiFileLoader();
        $oldSpecification = $loader->load($fixtureDirectory . '/openapi-v1.yaml');
        $newSpecification = $loader->load($fixtureDirectory . '/openapi-v2.json');
        $service = new ComparisonService();

        self::assertSame(
            $this->changeTuples($service->compare($oldSpecification, $newSpecification)),
            $this->changeTuples($service->compare($oldSpecification, $newSpecification))
        );
    }

    public function testComparisonOrderDoesNotDependOnPathDeclarationOrder(): void
    {
        $document = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Ordering API'],
            'paths' => [
                '/zeta' => ['get' => ['responses' => []]],
                '/alpha' => ['post' => ['responses' => []]],
            ],
        ];
        $reversedDocument = [...$document, 'paths' => array_reverse($document['paths'], true)];
        $service = new ComparisonService();
        $newSpecification = OpenApiSpecification::fromArray([
            'openapi' => '3.1.0',
            'info' => ['title' => 'Ordering API'],
            'paths' => [],
        ], 'new.yaml');

        $first = OpenApiSpecification::fromArray($document, 'old.yaml');
        $second = OpenApiSpecification::fromArray($reversedDocument, 'old.yaml');

        self::assertSame(
            $this->changeTuples($service->compare($first, $newSpecification)),
            $this->changeTuples($service->compare($second, $newSpecification))
        );
    }

    public function testJsonPointerEscapingResolvesComponentSchemaNames(): void
    {
        $reference = '#/components/schemas/Address~1Details~0Legacy';
        $old = $this->specificationWithReferencedSchema($reference, ['type' => 'string']);
        $new = $this->specificationWithReferencedSchema($reference, ['type' => 'integer']);

        $changes = (new ComparisonService())->compare($old, $new);

        $this->assertChange($changes, ChangeType::RESPONSE_SCHEMA, Severity::BREAKING, '/profile', 'changed type from string to integer');
    }

    /**
     * @param list<Change> $changes
     */
    private function assertChange(
        array $changes,
        ChangeType $type,
        Severity $severity,
        string $path,
        string $messageFragment,
    ): void {
        foreach ($changes as $change) {
            if (str_contains($change->message, $messageFragment)) {
                self::assertSame($type, $change->type);
                self::assertSame($severity, $change->severity);
                self::assertSame($path, $change->path);
                self::assertStringContainsString($messageFragment, $change->message);

                return;
            }
        }

        self::fail(sprintf('Expected %s %s change at %s containing "%s".', $severity->value, $type->value, $path, $messageFragment));
    }

    /**
     * @param list<Change> $changes
     * @return list<array{type: string, severity: string, path: string, message: string}>
     */
    private function changeTuples(array $changes): array
    {
        return array_map(static fn (Change $change): array => [
            'type' => $change->type->value,
            'severity' => $change->severity->value,
            'path' => $change->path,
            'message' => $change->message,
        ], $changes);
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function specificationWithReferencedSchema(string $reference, array $schema): OpenApiSpecification
    {
        return OpenApiSpecification::fromArray([
            'openapi' => '3.1.0',
            'info' => ['title' => 'Pointer API'],
            'paths' => ['/profile' => ['get' => ['responses' => ['200' => [
                'description' => 'Profile',
                'content' => ['application/json' => ['schema' => ['$ref' => $reference]]],
            ]]]]],
            'components' => ['schemas' => ['Address/Details~Legacy' => $schema]],
        ], 'pointer.json');
    }
}