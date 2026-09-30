<?php

declare(strict_types=1);

namespace ApiGuard\Symfony\Tests\Integration;

use ApiGuard\Application\ComparisonService;
use ApiGuard\CLI\ComparisonReportFormatter;
use ApiGuard\Domain\Comparison\Severity;
use ApiGuard\Infrastructure\OpenApi\OpenApiFileLoader;
use ApiGuard\Infrastructure\OpenApi\SpecificationLoaderInterface;
use ApiGuard\Domain\Specification\OpenApiSpecification;
use ApiGuard\Symfony\ApiGuardBundle;
use ApiGuard\Symfony\Console\CompareOpenApiCommand;
use ApiGuard\Symfony\DependencyInjection\ApiGuardExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use RuntimeException;

final class CompareOpenApiCommandTest extends TestCase
{
    public function testBundleExtensionRegistersCoreServicesAndConsoleCommand(): void
    {
        $container = new ContainerBuilder();
        $bundle = new ApiGuardBundle();
        $extension = $bundle->getContainerExtension();

        self::assertInstanceOf(ApiGuardExtension::class, $extension);
        $extension->load([], $container);

        self::assertArrayHasKey(OpenApiFileLoader::class, $container->getDefinitions());
        self::assertSame(OpenApiFileLoader::class, (string) $container->getAlias(SpecificationLoaderInterface::class));
        self::assertArrayHasKey(ComparisonService::class, $container->getDefinitions());
        self::assertArrayHasKey(ComparisonReportFormatter::class, $container->getDefinitions());
        self::assertTrue($container->getDefinition(CompareOpenApiCommand::class)->hasTag('console.command'));
    }

    public function testUnchangedSpecificationsReturnSuccessAndUseTheCoreReport(): void
    {
        $fixture = $this->fixturePath('OpenApi/valid-openapi.json');
        $result = $this->runCommand($fixture, $fixture);

        self::assertSame(CompareOpenApiCommand::EXIT_SUCCESS, $result['exitCode']);
        self::assertStringContainsString('No changes detected.', $result['display']);
    }

    public function testBreakingChangesReturnOneAndUseTheCoreFormatter(): void
    {
        $result = $this->runCommand(
            $this->fixturePath('RealWorld/openapi-v1.yaml'),
            $this->fixturePath('RealWorld/openapi-v2.json')
        );

        self::assertSame(CompareOpenApiCommand::EXIT_BREAKING_CHANGES, $result['exitCode']);
        self::assertStringContainsString('BREAKING CHANGES', $result['display']);
        self::assertStringContainsString('property email was removed', $result['display']);
    }

    public function testNonBreakingOnlyChangeReturnsSuccess(): void
    {
        $old = $this->writeSpecification([]);
        $new = $this->writeSpecification(['/health' => ['get' => ['responses' => ['200' => ['description' => 'Healthy']]]]]);
        $result = $this->runCommand($old, $new);

        self::assertSame(CompareOpenApiCommand::EXIT_SUCCESS, $result['exitCode']);
        self::assertStringContainsString('NON-BREAKING CHANGES', $result['display']);
        self::assertStringNotContainsString("\nBREAKING CHANGES\n", $result['display']);
    }

    public function testMissingFileReturnsTwo(): void
    {
        $missing = sys_get_temp_dir() . '/api-guard-symfony-missing-' . bin2hex(random_bytes(8));
        $result = $this->runCommand($missing, $this->fixturePath('OpenApi/valid-openapi.yaml'));

        self::assertSame(CompareOpenApiCommand::EXIT_INVALID_INPUT, $result['exitCode']);
        self::assertStringContainsString('Input file does not exist', $result['display']);
    }

    public function testInvalidSpecificationReturnsThree(): void
    {
        $result = $this->runCommand(
            $this->fixturePath('OpenApi/invalid-yaml.yaml'),
            $this->fixturePath('OpenApi/valid-openapi.yaml')
        );

        self::assertSame(CompareOpenApiCommand::EXIT_INVALID_SPECIFICATION, $result['exitCode']);
        self::assertStringContainsString('Invalid OpenAPI specification', $result['display']);
    }

    public function testUnexpectedLoaderFailureReturnsFour(): void
    {
        $loader = new class implements SpecificationLoaderInterface {
            public function load(string $path): OpenApiSpecification
            {
                throw new RuntimeException('injected loader failure');
            }
        };
        $valid = $this->fixturePath('OpenApi/valid-openapi.yaml');
        $result = $this->runCommand($valid, $valid, $loader);

        self::assertSame(CompareOpenApiCommand::EXIT_UNEXPECTED_ERROR, $result['exitCode']);
        self::assertStringContainsString('Unexpected error while loading specifications', $result['display']);
    }

    /**
     * @param array<string, mixed> $paths
     */
    private function writeSpecification(array $paths): string
    {
        $path = tempnam(sys_get_temp_dir(), 'api-guard-symfony-');
        if ($path === false) {
            self::fail('Unable to create a temporary OpenAPI specification.');
        }

        $document = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Symfony test API', 'version' => '1.0.0'],
            'paths' => $paths,
        ];
        file_put_contents($path, json_encode($document, JSON_THROW_ON_ERROR));

        return $path;
    }

    /**
     * @return array{exitCode: int, display: string}
     */
    private function runCommand(
        string $oldPath,
        string $newPath,
        ?SpecificationLoaderInterface $loader = null,
    ): array
    {
        $application = new Application();
        $application->addCommands([new CompareOpenApiCommand(
            $loader ?? new OpenApiFileLoader(),
            new ComparisonService(),
            new ComparisonReportFormatter()
        )]);
        $commandTester = new CommandTester($application->find('api-guard:check'));
        $exitCode = $commandTester->execute(['old' => $oldPath, 'new' => $newPath], ['decorated' => false]);

        return ['exitCode' => $exitCode, 'display' => $commandTester->getDisplay()];
    }

    private function fixturePath(string $path): string
    {
        return dirname(__DIR__, 4) . '/tests/Fixtures/' . $path;
    }
}