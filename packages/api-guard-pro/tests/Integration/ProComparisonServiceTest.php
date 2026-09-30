<?php

declare(strict_types=1);

namespace ApiGuard\Pro\Tests\Integration;

use ApiGuard\Application\ComparisonService;
use ApiGuard\Infrastructure\OpenApi\OpenApiFileLoader;
use ApiGuard\Pro\Application\ProComparisonService;
use ApiGuard\Pro\Ignore\IgnoreConfigurationLoader;
use PHPUnit\Framework\TestCase;

final class ProComparisonServiceTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            unlink($path);
        }

        parent::tearDown();
    }

    public function testIgnoredBreakingChangeRemainsAuditableAndOtherBreakingChangesRemainActive(): void
    {
        $root = dirname(__DIR__, 4);
        $configPath = $this->writeConfiguration(<<<'YAML'
version: 1
ignore:
  - type: response_schema
    path: /users/{userId}
    message: GET response 200 application/json property email was removed.
    reason: Existing API consumer has a migration window.
YAML);
        $result = $this->service()->compareFiles(
            $root . '/tests/Fixtures/RealWorld/openapi-v1.yaml',
            $root . '/tests/Fixtures/RealWorld/openapi-v2.json',
            $configPath
        );

        self::assertCount(1, $result->ignoredChanges);
        self::assertSame('Existing API consumer has a migration window.', $result->ignoredChanges[0]->reason);
        self::assertTrue($result->hasBreakingChanges());
        self::assertNotEmpty($result->changes);
    }

    public function testPolicyCanSuppressTheOnlyBreakingChange(): void
    {
        $oldPath = $this->writeSpecification(['/legacy' => ['get' => ['responses' => []]]]);
        $newPath = $this->writeSpecification([]);
        $configPath = $this->writeConfiguration(<<<'YAML'
version: 1
ignore:
  - type: path
    path: /legacy
    reason: Removal approved in change request ARCH-142.
YAML);

        $result = $this->service()->compareFiles($oldPath, $newPath, $configPath);

        self::assertSame([], $result->changes);
        self::assertCount(1, $result->ignoredChanges);
        self::assertFalse($result->hasBreakingChanges());
    }

    private function service(): ProComparisonService
    {
        return new ProComparisonService(new OpenApiFileLoader(), new ComparisonService(), new IgnoreConfigurationLoader());
    }

    /**
     * @param array<string, mixed> $paths
     */
    private function writeSpecification(array $paths): string
    {
        $document = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Pro test API', 'version' => '1.0.0'],
            'paths' => $paths,
        ];

        return $this->writeTemporaryFile(json_encode($document, JSON_THROW_ON_ERROR));
    }

    private function writeConfiguration(string $configuration): string
    {
        return $this->writeTemporaryFile($configuration);
    }

    private function writeTemporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'api-guard-pro-');
        if ($path === false) {
            self::fail('Unable to create a temporary Pro fixture.');
        }

        $this->temporaryFiles[] = $path;
        file_put_contents($path, $contents);

        return $path;
    }
}