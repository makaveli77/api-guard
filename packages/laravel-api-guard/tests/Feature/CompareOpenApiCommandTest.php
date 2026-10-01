<?php

declare(strict_types=1);

namespace ApiGuard\Laravel\Tests\Feature;

use ApiGuard\Domain\Specification\OpenApiSpecification;
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
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            unlink($path);
        }

        parent::tearDown();
    }

    public function testServiceProviderRegistersTheLoaderAndArtisanCommand(): void
    {
        self::assertInstanceOf(OpenApiFileLoader::class, $this->application()->make(SpecificationLoaderInterface::class));

        $this->artisanCommand('list')
            ->expectsOutputToContain('api:guard')
            ->assertExitCode(0);
    }

    public function testUnchangedSpecificationsSucceedAndPrintTheSharedReport(): void
    {
        $specification = $this->writeSpecification([]);

        $this->artisanCommand('api:guard', ['old' => $specification, 'new' => $specification])
            ->expectsOutputToContain('No changes detected.')
            ->assertExitCode(CompareOpenApiCommand::EXIT_SUCCESS);
    }

    public function testBreakingChangesReturnOneAndUseTheCoreFormatter(): void
    {
        $old = $this->userSpecification('email');
        $new = $this->userSpecification('name');
        $output = new BufferedOutput();
        $kernel = $this->application()->make(Kernel::class);
        if (!$kernel instanceof Kernel) {
            throw new RuntimeException('The Laravel console kernel is not registered.');
        }

        $exitCode = $kernel->call('api:guard', ['old' => $old, 'new' => $new], $output);

        self::assertSame(CompareOpenApiCommand::EXIT_BREAKING_CHANGES, $exitCode);
        self::assertStringContainsString('BREAKING CHANGES', $output->fetch());
    }

    public function testMissingInputFileReturnsTwo(): void
    {
        $missing = sys_get_temp_dir() . '/api-guard-laravel-missing-' . bin2hex(random_bytes(8));
        $valid = $this->writeSpecification([]);

        $this->artisanCommand('api:guard', ['old' => $missing, 'new' => $valid])
            ->expectsOutputToContain('Input file does not exist')
            ->assertExitCode(CompareOpenApiCommand::EXIT_INVALID_INPUT);
    }

    public function testInvalidSpecificationReturnsThree(): void
    {
        $invalid = $this->writeTemporaryFile("openapi: [\n");
        $valid = $this->writeSpecification([]);

        $this->artisanCommand('api:guard', ['old' => $invalid, 'new' => $valid])
            ->expectsOutputToContain('Invalid OpenAPI specification')
            ->assertExitCode(CompareOpenApiCommand::EXIT_INVALID_SPECIFICATION);
    }

    public function testUnexpectedLoaderFailureReturnsFour(): void
    {
        $this->application()->instance(SpecificationLoaderInterface::class, new class implements SpecificationLoaderInterface {
            public function load(string $path): OpenApiSpecification
            {
                throw new RuntimeException('injected loader failure');
            }
        });
        $valid = $this->writeSpecification([]);

        $this->artisanCommand('api:guard', ['old' => $valid, 'new' => $valid])
            ->expectsOutputToContain('Unexpected error while loading specifications')
            ->assertExitCode(CompareOpenApiCommand::EXIT_UNEXPECTED_ERROR);
    }

    private function userSpecification(string $property): string
    {
        $schema = ['type' => 'object', 'properties' => [$property => ['type' => 'string']]];
        $response = ['description' => 'User returned', 'content' => ['application/json' => ['schema' => $schema]]];
        $operation = ['responses' => ['200' => $response]];

        return $this->writeSpecification(['/users' => ['get' => $operation]]);
    }

    /**
     * @param array<string, mixed> $paths
     */
    private function writeSpecification(array $paths): string
    {
        return $this->writeTemporaryFile(json_encode([
            'openapi' => '3.1.0',
            'info' => ['title' => 'Laravel adapter test API', 'version' => '1.0.0'],
            'paths' => $paths,
        ], JSON_THROW_ON_ERROR));
    }

    private function writeTemporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'api-guard-laravel-');
        if ($path === false) {
            self::fail('Unable to create a temporary OpenAPI file.');
        }

        $this->temporaryFiles[] = $path;
        file_put_contents($path, $contents);

        return $path;
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