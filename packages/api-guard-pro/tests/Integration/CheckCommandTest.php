<?php

declare(strict_types=1);

namespace ApiGuard\Pro\Tests\Integration;

use ApiGuard\Application\ComparisonService;
use ApiGuard\CLI\ComparisonReportFormatter;
use ApiGuard\Domain\Specification\OpenApiSpecification;
use ApiGuard\Infrastructure\OpenApi\OpenApiFileLoader;
use ApiGuard\Infrastructure\OpenApi\SpecificationLoaderInterface;
use ApiGuard\Pro\Application\ProComparisonService;
use ApiGuard\Pro\Console\CheckCommand;
use ApiGuard\Pro\Ignore\IgnoreConfigurationLoader;
use ApiGuard\Pro\Report\JsonReportFormatter;
use ApiGuard\Pro\Report\MarkdownReportFormatter;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class CheckCommandTest extends TestCase
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

    public function testDefaultTextFormatSucceedsForCompatibleSpecifications(): void
    {
        $fixture = $this->fixturePath('OpenApi/valid-openapi.yaml');
        $result = $this->runCommand(['--old' => $fixture, '--new' => $fixture]);

        self::assertSame(0, $result['exitCode']);
        self::assertStringContainsString('No changes detected.', $result['display']);
    }

    public function testBreakingChangesFailInJsonFormat(): void
    {
        $result = $this->runCommand([
            '--old' => $this->fixturePath('RealWorld/openapi-v1.yaml'),
            '--new' => $this->fixturePath('RealWorld/openapi-v2.json'),
            '--format' => 'json',
        ]);
        $decoded = json_decode($result['display'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $summary = $decoded['summary'] ?? null;
        self::assertIsArray($summary);

        self::assertSame(1, $result['exitCode']);
        self::assertSame(1, $decoded['schema_version'] ?? null);
        self::assertTrue($summary['has_breaking_changes'] ?? false);
    }

    public function testMarkdownFormatIncludesIgnoredChangeReasonAndCanPassCi(): void
    {
        $old = $this->writeSpecification(['/legacy' => ['get' => ['responses' => []]]]);
        $new = $this->writeSpecification([]);
        $config = $this->writeConfiguration(<<<'YAML'
version: 1
ignore:
  - type: path
    path: /legacy
    reason: Approved in ARCH-142.
YAML);
        $result = $this->runCommand([
            '--old' => $old,
            '--new' => $new,
            '--ignore-config' => $config,
            '--format' => 'markdown',
        ]);

        self::assertSame(0, $result['exitCode']);
        self::assertStringContainsString('## Ignored changes', $result['display']);
        self::assertStringContainsString('Reason: Approved in ARCH-142.', $result['display']);
    }

    public function testInvalidFormatAndIgnoreConfigurationReturnTwo(): void
    {
        $fixture = $this->fixturePath('OpenApi/valid-openapi.yaml');
        $invalidFormat = $this->runCommand([
            '--old' => $fixture,
            '--new' => $fixture,
            '--format' => 'html',
        ]);
        self::assertSame(2, $invalidFormat['exitCode']);
        self::assertStringContainsString('Unknown report format', $invalidFormat['display']);

        $config = $this->writeConfiguration("version: 2\nignore: []\n");
        $invalidConfiguration = $this->runCommand([
            '--old' => $fixture,
            '--new' => $fixture,
            '--ignore-config' => $config,
        ]);
        self::assertSame(2, $invalidConfiguration['exitCode']);
        self::assertStringContainsString('Invalid ignore configuration', $invalidConfiguration['display']);
    }

    public function testMissingFilesAndInvalidSpecificationsReturnTheirStatuses(): void
    {
        $missing = sys_get_temp_dir() . '/api-guard-pro-missing-' . bin2hex(random_bytes(8));
        $missingResult = $this->runCommand([
            '--old' => $missing,
            '--new' => $this->fixturePath('OpenApi/valid-openapi.yaml'),
        ]);
        self::assertSame(2, $missingResult['exitCode']);

        $invalidResult = $this->runCommand([
            '--old' => $this->fixturePath('OpenApi/invalid-yaml.yaml'),
            '--new' => $this->fixturePath('OpenApi/valid-openapi.yaml'),
        ]);
        self::assertSame(3, $invalidResult['exitCode']);
        self::assertStringContainsString('Invalid OpenAPI specification', $invalidResult['display']);
    }

    public function testUnexpectedLoaderErrorReturnsFour(): void
    {
        $loader = new class implements SpecificationLoaderInterface {
            public function load(string $path): OpenApiSpecification
            {
                throw new RuntimeException('injected loader failure');
            }
        };
        $fixture = $this->fixturePath('OpenApi/valid-openapi.yaml');
        $result = $this->runCommand(['--old' => $fixture, '--new' => $fixture], $loader);

        self::assertSame(4, $result['exitCode']);
        self::assertStringContainsString('Unexpected error while comparing', $result['display']);
    }

    /**
     * @param array<string, string> $arguments
     * @return array{exitCode: int, display: string}
     */
    private function runCommand(array $arguments, ?SpecificationLoaderInterface $loader = null): array
    {
        $loader ??= new OpenApiFileLoader();
        $proService = new ProComparisonService($loader, new ComparisonService(), new IgnoreConfigurationLoader());
        $command = new CheckCommand(
            $proService,
            new JsonReportFormatter(),
            new MarkdownReportFormatter(),
            new ComparisonReportFormatter()
        );
        $application = new Application('API Guard Pro');
        $application->addCommands([$command]);
        $tester = new CommandTester($application->find('check'));
        $exitCode = $tester->execute($arguments, ['decorated' => false]);

        return ['exitCode' => $exitCode, 'display' => $tester->getDisplay()];
    }

    private function fixturePath(string $path): string
    {
        return dirname(__DIR__, 4) . '/tests/Fixtures/' . $path;
    }

    /**
     * @param array<string, mixed> $paths
     */
    private function writeSpecification(array $paths): string
    {
        return $this->writeTemporaryFile(json_encode([
            'openapi' => '3.1.0',
            'info' => ['title' => 'Pro CLI API', 'version' => '1.0.0'],
            'paths' => $paths,
        ], JSON_THROW_ON_ERROR));
    }

    private function writeConfiguration(string $configuration): string
    {
        return $this->writeTemporaryFile($configuration);
    }

    private function writeTemporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'api-guard-pro-');
        if ($path === false) {
            self::fail('Unable to create a temporary fixture.');
        }
        $this->temporaryFiles[] = $path;
        file_put_contents($path, $contents);

        return $path;
    }
}