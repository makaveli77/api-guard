<?php

declare(strict_types=1);

namespace ApiGuard\Tests\Integration;

use ApiGuard\CLI\ComparisonReportFormatter;
use ApiGuard\Domain\Comparison\Change;
use ApiGuard\Domain\Comparison\ChangeType;
use ApiGuard\Domain\Comparison\Severity;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ApiGuardCommandTest extends TestCase
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

    public function testSuccessfulComparisonHasZeroExitCodeAndReadableOutput(): void
    {
        $specification = $this->fixturePath('valid-openapi.yaml');

        $result = $this->runCommand(['check', '--old=' . $specification, '--new=' . $specification]);

        self::assertSame(0, $result['exitCode']);
        self::assertStringContainsString('API Guard', $result['stdout']);
        self::assertStringContainsString('Comparing:', $result['stdout']);
        self::assertStringContainsString('Breaking changes:     0', $result['stdout']);
        self::assertStringContainsString('No changes detected.', $result['stdout']);
        self::assertSame('', $result['stderr']);
    }

    public function testBreakingComparisonHasExitCodeOneAndClearlyIdentifiesChanges(): void
    {
        $oldSpecification = $this->writeDocument(['/users' => ['get' => ['responses' => ['200' => ['description' => 'OK']]]]]);
        $newSpecification = $this->writeDocument([]);

        $result = $this->runCommand(['check', '--old=' . $oldSpecification, '--new=' . $newSpecification]);

        self::assertSame(1, $result['exitCode']);
        self::assertStringContainsString('BREAKING CHANGES', $result['stdout']);
        self::assertStringContainsString('! /users [PATH]', $result['stdout']);
        self::assertStringContainsString('Endpoint was removed.', $result['stdout']);
        self::assertSame('', $result['stderr']);
    }

    public function testNonBreakingChangesAreReportedWithoutFailingCi(): void
    {
        $oldSpecification = $this->writeDocument([]);
        $newSpecification = $this->writeDocument([
            '/z-users' => ['get' => ['responses' => ['200' => ['description' => 'OK']]]],
            '/a-users' => ['get' => ['responses' => ['200' => ['description' => 'OK']]]],
        ]);

        $result = $this->runCommand(['check', '--old=' . $oldSpecification, '--new=' . $newSpecification]);

        self::assertSame(0, $result['exitCode']);
        self::assertStringContainsString('NON-BREAKING CHANGES', $result['stdout']);
        self::assertStringContainsString('+ /a-users [PATH]', $result['stdout']);
        self::assertStringContainsString('+ /z-users [PATH]', $result['stdout']);
        self::assertLessThan(strpos($result['stdout'], '+ /z-users'), strpos($result['stdout'], '+ /a-users'));
    }

    public function testInvalidCommandArgumentsReturnExitCodeTwo(): void
    {
        $missingOption = $this->runCommand(['check', '--old=openapi.yaml']);
        self::assertSame(2, $missingOption['exitCode']);
        self::assertStringContainsString('both --old=<file> and --new=<file> are required', $missingOption['stderr']);

        $unknownOption = $this->runCommand(['check', '--old=openapi.yaml', '--new=openapi.yaml', '--format=json']);
        self::assertSame(2, $unknownOption['exitCode']);
        self::assertStringContainsString('unknown option "--format"', $unknownOption['stderr']);

        $wrongCommand = $this->runCommand(['compare']);
        self::assertSame(2, $wrongCommand['exitCode']);
        self::assertStringContainsString('expected the "check" command', $wrongCommand['stderr']);
    }

    public function testMissingInputFileReturnsExitCodeTwo(): void
    {
        $missingPath = sys_get_temp_dir() . '/api-guard-missing-' . bin2hex(random_bytes(8));
        $validPath = $this->fixturePath('valid-openapi.yaml');

        $result = $this->runCommand(['check', '--old=' . $missingPath, '--new=' . $validPath]);

        self::assertSame(2, $result['exitCode']);
        self::assertStringContainsString('Input file does not exist', $result['stderr']);
        self::assertStringNotContainsString('BREAKING CHANGES', $result['stdout']);
    }

    public function testInvalidSpecificationsReturnExitCodeThree(): void
    {
        foreach (['invalid-yaml.yaml', 'non-openapi-3.yaml'] as $fixture) {
            $invalidPath = $this->fixturePath($fixture);
            $validPath = $this->fixturePath('valid-openapi.json');

            $result = $this->runCommand(['check', '--old=' . $invalidPath, '--new=' . $validPath]);

            self::assertSame(3, $result['exitCode'], $fixture);
            self::assertStringContainsString('Invalid OpenAPI specification', $result['stderr'], $fixture);
        }
    }

    public function testHelpReturnsZeroAndShowsCommandUsage(): void
    {
        $result = $this->runCommand(['--help']);

        self::assertSame(0, $result['exitCode']);
        self::assertStringContainsString('Usage: vendor/bin/api-guard check --old=<file> --new=<file>', $result['stdout']);
    }

    public function testUnexpectedLoaderFailureReturnsExitCodeFour(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $validPath = var_export($this->fixturePath('valid-openapi.yaml'), true);
        $bootstrap = var_export($projectRoot . '/vendor/autoload.php', true);
        $script = sprintf(<<<'PHP'
<?php
require %s;
$loader = new class implements \ApiGuard\Infrastructure\OpenApi\SpecificationLoaderInterface {
    public function load(string $path): \ApiGuard\Domain\Specification\OpenApiSpecification
    {
        throw new \RuntimeException('injected loader failure');
    }
};
$command = new \ApiGuard\CLI\ApiGuardCommand($loader);
exit($command->run(['api-guard', 'check', '--old=' . %s, '--new=' . %s]));
PHP, $bootstrap, $validPath, $validPath);
        $scriptPath = $this->writeTemporaryFile($script);

        $result = $this->runProcess([PHP_BINARY, $scriptPath]);

        self::assertSame(4, $result['exitCode']);
        self::assertStringContainsString('Unexpected error while loading specifications', $result['stderr']);
    }

    public function testFormatterKeepsAllSeverityGroupsDistinct(): void
    {
        $report = (new ComparisonReportFormatter())->format('old.yaml', 'new.yaml', [
            new Change(ChangeType::UNKNOWN, Severity::INFO, '/users', 'Informational note.'),
            new Change(ChangeType::PATH, Severity::NON_BREAKING, '/teams', 'Endpoint was added.'),
            new Change(ChangeType::PATH, Severity::BREAKING, '/users', 'Endpoint was removed.'),
        ]);

        self::assertStringContainsString('Breaking changes:     1', $report);
        self::assertStringContainsString('Non-breaking changes: 1', $report);
        self::assertStringContainsString('Informational changes: 1', $report);
        self::assertLessThan(strpos($report, 'NON-BREAKING CHANGES'), strpos($report, 'BREAKING CHANGES'));
        self::assertLessThan(strpos($report, 'INFORMATIONAL CHANGES'), strpos($report, 'NON-BREAKING CHANGES'));
    }

    /**
     * @param list<string> $arguments
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    private function runCommand(array $arguments): array
    {
        $projectRoot = dirname(__DIR__, 2);
        $command = [PHP_BINARY, $projectRoot . '/bin/api-guard', ...$arguments];
        return $this->runProcess($command);
    }

    /**
     * @param list<string> $command
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    private function runProcess(array $command): array
    {
        $projectRoot = dirname(__DIR__, 2);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = proc_open($command, $descriptors, $pipes, $projectRoot);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start the API Guard CLI process.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [
            'exitCode' => $exitCode,
            'stdout' => $stdout === false ? '' : $stdout,
            'stderr' => $stderr === false ? '' : $stderr,
        ];
    }

    private function fixturePath(string $name): string
    {
        return dirname(__DIR__, 2) . '/tests/Fixtures/OpenApi/' . $name;
    }

    /**
     * @param array<string, mixed> $paths
     */
    private function writeDocument(array $paths): string
    {
        $document = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'CLI test API', 'version' => '1.0.0'],
            'paths' => $paths,
        ];

        return $this->writeTemporaryFile(json_encode($document, JSON_THROW_ON_ERROR));
    }

    private function writeTemporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'api-guard-');
        if ($path === false) {
            throw new RuntimeException('Unable to create temporary file.');
        }
        $this->temporaryFiles[] = $path;

        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Unable to write temporary file.');
        }

        return $path;
    }
}