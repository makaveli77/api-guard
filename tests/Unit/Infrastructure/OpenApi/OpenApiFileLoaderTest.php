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
}
