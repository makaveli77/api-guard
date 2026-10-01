<?php

declare(strict_types=1);

namespace ApiGuard\Tests\Unit\Infrastructure\OpenApi;

use ApiGuard\Infrastructure\OpenApi\OpenApiFileLoader;
use ApiGuard\Infrastructure\OpenApi\SpecificationLoadException;
use PHPUnit\Framework\TestCase;

final class OpenApiFileLoaderTest extends TestCase
{
    private OpenApiFileLoader $loader;

    protected function setUp(): void
    {
        $this->loader = new OpenApiFileLoader();
    }

    public function testItLoadsValidYamlSpecification(): void
    {
        $result = $this->loader->load(__DIR__ . '/../../../Fixtures/OpenApi/valid-openapi.yaml');

        $this->assertSame('3.1.0', $result->version);
        $this->assertSame('User API', $result->title);
        $this->assertArrayHasKey('/users', $result->paths);
        $this->assertTrue($result->hasPath('/users'));
    }

    public function testItLoadsValidJsonSpecification(): void
    {
        $result = $this->loader->load(__DIR__ . '/../../../Fixtures/OpenApi/valid-openapi.json');

        $this->assertSame('3.0.3', $result->version);
        $this->assertSame('Orders API', $result->title);
        $this->assertArrayHasKey('/orders', $result->paths);
    }

    public function testItThrowsForInvalidYaml(): void
    {
        $this->expectException(SpecificationLoadException::class);
        $this->expectExceptionMessage('Invalid YAML');

        $this->loader->load(__DIR__ . '/../../../Fixtures/OpenApi/invalid-yaml.yaml');
    }

    public function testItThrowsForInvalidJson(): void
    {
        $this->expectException(SpecificationLoadException::class);
        $this->expectExceptionMessage('Invalid JSON');

        $this->loader->load(__DIR__ . '/../../../Fixtures/OpenApi/invalid-json.json');
    }

    public function testItRejectsInvalidOpenApiStructure(): void
    {
        $this->expectException(SpecificationLoadException::class);
        $this->expectExceptionMessage('missing the required paths object');

        $this->loader->load(__DIR__ . '/../../../Fixtures/OpenApi/invalid-structure.yaml');
    }

    public function testItRejectsOperationsWithoutResponses(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'api-guard-missing-responses-');
        self::assertNotFalse($path);
        file_put_contents($path, json_encode([
            'openapi' => '3.1.0',
            'info' => ['title' => 'Malformed API', 'version' => '1.0.0'],
            'paths' => ['/broken' => ['get' => []]],
        ], JSON_THROW_ON_ERROR));

        try {
            $this->expectException(SpecificationLoadException::class);
            $this->expectExceptionMessage('must define a non-empty responses object');
            $this->loader->load($path);
        } finally {
            unlink($path);
        }
    }

    public function testItAcceptsOpenApi30And31ResponseObjectsAndReferences(): void
    {
        foreach (['3.0.3', '3.1.0'] as $version) {
            $specification = $this->loadDocument([
                'openapi' => $version,
                'info' => ['title' => 'Response API', 'version' => '1.0.0'],
                'paths' => ['/users' => ['get' => ['responses' => [
                    '200' => [
                        'description' => 'Users returned',
                        'headers' => ['X-Request-ID' => ['description' => 'Request identifier', 'schema' => ['type' => 'string']]],
                        'content' => ['application/json' => ['schema' => ['type' => 'array', 'items' => ['type' => 'string']]]],
                        'links' => ['next' => ['operationId' => 'listNextPage']],
                    ],
                    '404' => [
                        '$ref' => '#/components/responses/NotFound',
                        'summary' => 'Missing resource',
                        'description' => 'Reference Object siblings are preserved.',
                    ],
                ]]]],
            ]);

            self::assertSame($version, $specification->version);
            self::assertArrayHasKey('/users', $specification->paths);
        }
    }

    public function testItRejectsMalformedResponseEntriesAndFields(): void
    {
        $invalidResponses = [
            ['200' => null],
            ['200' => 'OK'],
            ['200' => []],
            ['200' => ['description' => 200]],
            ['200' => ['$ref' => '  ']],
            ['200' => ['description' => 'OK', 'content' => null]],
            ['200' => ['description' => 'OK', 'content' => ['application/json' => null]]],
            ['200' => ['description' => 'OK', 'headers' => ['X-Request-ID' => null]]],
            ['200' => ['description' => 'OK', 'links' => ['next' => null]]],
        ];

        foreach ($invalidResponses as $responses) {
            try {
                $this->loadDocument([
                    'openapi' => '3.1.0',
                    'info' => ['title' => 'Malformed response API', 'version' => '1.0.0'],
                    'paths' => ['/users' => ['get' => ['responses' => $responses]]],
                ]);
            } catch (SpecificationLoadException $exception) {
                self::assertStringContainsString('response', strtolower($exception->getMessage()));
                continue;
            }

            self::fail('Expected malformed response entry to be rejected.');
        }
    }

    public function testItRejectsNonOpenApi3Document(): void
    {
        $this->expectException(SpecificationLoadException::class);
        $this->expectExceptionMessage('not a valid OpenAPI 3.x document');

        $this->loader->load(__DIR__ . '/../../../Fixtures/OpenApi/non-openapi-3.yaml');
    }

    public function testItThrowsForMissingFile(): void
    {
        $this->expectException(SpecificationLoadException::class);
        $this->expectExceptionMessage('not found');

        $this->loader->load(__DIR__ . '/../../../Fixtures/OpenApi/does-not-exist.yaml');
    }

    public function testItThrowsForUnreadableFile(): void
    {
        $path = sys_get_temp_dir() . '/api-guard-unreadable-openapi.yaml';
        file_put_contents($path, "openapi: 3.1.0\ninfo:\n  title: Hidden API\n  version: 1.0.0\npaths: {}\n");
        chmod($path, 0000);

        try {
            $this->expectException(SpecificationLoadException::class);
            $this->expectExceptionMessage('not readable');

            $this->loader->load($path);
        } finally {
            chmod($path, 0600);
            unlink($path);
        }
    }

    public function testItRejectsEmptyFile(): void
    {
        $path = sys_get_temp_dir() . '/api-guard-empty-openapi.yaml';
        file_put_contents($path, '');

        try {
            $this->expectException(SpecificationLoadException::class);
            $this->expectExceptionMessage('is empty');

            $this->loader->load($path);
        } finally {
            unlink($path);
        }
    }

    /**
     * @param array<string, mixed> $document
     */
    private function loadDocument(array $document): \ApiGuard\Domain\Specification\OpenApiSpecification
    {
        $path = tempnam(sys_get_temp_dir(), 'api-guard-response-spec-');
        if ($path === false) {
            self::fail('Unable to create a temporary OpenAPI specification.');
        }

        file_put_contents($path, json_encode($document, JSON_THROW_ON_ERROR));
        try {
            return $this->loader->load($path);
        } finally {
            unlink($path);
        }
    }
}
