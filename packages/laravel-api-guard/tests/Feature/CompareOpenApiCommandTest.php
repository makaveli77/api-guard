<?php

declare(strict_types=1);

namespace ApiGuard\Laravel\Tests\Feature;

use ApiGuard\Infrastructure\OpenApi\OpenApiFileLoader;
use ApiGuard\Infrastructure\OpenApi\SpecificationLoaderInterface;
use ApiGuard\Laravel\Console\CompareOpenApiCommand;
use ApiGuard\Laravel\Tests\TestCase;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Testing\PendingCommand;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;

final class CompareOpenApiCommandTest extends TestCase
{
    public function testServiceProviderRegistersTheLoaderAndArtisanCommand(): void
    {
        self::assertInstanceOf(OpenApiFileLoader::class, $this->application()->make(SpecificationLoaderInterface::class));

        $this->artisanCommand('list')
            ->expectsOutputToContain('api:guard')
            ->assertExitCode(0);
    }

    public function testUnchangedSpecificationsSucceedAndPrintTheSharedReport(): void
    {
        $specification = $this->fixturePath('OpenApi/valid-openapi.yaml');

        $this->artisanCommand('api:guard', ['old' => $specification, 'new' => $specification])
            ->expectsOutputToContain('No changes detected.')
            ->assertExitCode(CompareOpenApiCommand::EXIT_SUCCESS);
    }

    public function testBreakingChangesReturnOneAndUseTheCoreFormatter(): void
    {
        $old = $this->fixturePath('RealWorld/openapi-v1.yaml');
        $new = $this->fixturePath('RealWorld/openapi-v2.json');
        $output = new BufferedOutput();

        $exitCode = $this->application()->make(Kernel::class)->call('api:guard', ['old' => $old, 'new' => $new], $output);
        $report = $output->fetch();

        self::assertSame(CompareOpenApiCommand::EXIT_BREAKING_CHANGES, $exitCode);
        self::assertStringContainsString('BREAKING CHANGES', $report);
        self::assertStringContainsString('property email was removed', $report);
    }

    public function testMissingInputFileReturnsTwo(): void
    {
        $valid = $this->fixturePath('OpenApi/valid-openapi.yaml');
        $missing = sys_get_temp_dir() . '/api-guard-laravel-missing-' . bin2hex(random_bytes(8));

        $this->artisanCommand('api:guard', ['old' => $missing, 'new' => $valid])
            ->expectsOutputToContain('Input file does not exist')
            ->assertExitCode(CompareOpenApiCommand::EXIT_INVALID_INPUT);
    }

    public function testInvalidSpecificationReturnsThree(): void
    {
        $invalid = $this->fixturePath('OpenApi/invalid-yaml.yaml');
        $valid = $this->fixturePath('OpenApi/valid-openapi.yaml');

        $this->artisanCommand('api:guard', ['old' => $invalid, 'new' => $valid])
            ->expectsOutputToContain('Invalid OpenAPI specification')
            ->assertExitCode(CompareOpenApiCommand::EXIT_INVALID_SPECIFICATION);
    }

    public function testUnexpectedLoaderFailureReturnsFour(): void
    {
        $this->application()->instance(SpecificationLoaderInterface::class, new class implements SpecificationLoaderInterface {
            public function load(string $path): \ApiGuard\Domain\Specification\OpenApiSpecification
            {
                throw new RuntimeException('injected loader failure');
            }
        });
        $valid = $this->fixturePath('OpenApi/valid-openapi.yaml');

        $this->artisanCommand('api:guard', ['old' => $valid, 'new' => $valid])
            ->expectsOutputToContain('Unexpected error while loading specifications')
            ->assertExitCode(CompareOpenApiCommand::EXIT_UNEXPECTED_ERROR);
    }

    private function fixturePath(string $path): string
    {
        return dirname(__DIR__, 4) . '/tests/Fixtures/' . $path;
    }

    private function application(): Application
    {
        if (!$this->app instanceof Application) {
            throw new RuntimeException('The Laravel test application is not bootstrapped.');
        }

        return $this->app;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function artisanCommand(string $command, array $arguments = []): PendingCommand
    {
        $pendingCommand = $this->artisan($command, $arguments);
        if (!$pendingCommand instanceof PendingCommand) {
            throw new RuntimeException('Laravel did not create a pending Artisan command.');
        }

        return $pendingCommand;
    }
}