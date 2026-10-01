<?php

declare(strict_types=1);

namespace ApiGuard\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class GitHubActionConfigurationTest extends TestCase
{
    public function testCompositeActionDeclaresRequiredInputsAndRunsTheExistingCli(): void
    {
        $root = dirname(__DIR__, 2);
        $action = $this->mapping(Yaml::parseFile($root . '/action.yml'));
        $inputs = $this->mapping($action['inputs'] ?? null);
        $runs = $this->mapping($action['runs'] ?? null);
        $steps = $this->sequence($runs['steps'] ?? null);
        $setupStep = $this->mapping($steps[0] ?? null);
        $installStep = $this->mapping($steps[1] ?? null);
        $compareStep = $this->mapping($steps[2] ?? null);

        self::assertSame('composite', $runs['using'] ?? null);
        self::assertTrue($this->mapping($inputs['old'] ?? null)['required'] ?? false);
        self::assertTrue($this->mapping($inputs['new'] ?? null)['required'] ?? false);
        self::assertFileExists($root . '/composer.lock');

        self::assertSame('shivammathur/setup-php@v2', $setupStep['uses'] ?? null);
        $installCommand = $this->stringValue($installStep['run'] ?? null);
        $compareCommand = $this->stringValue($compareStep['run'] ?? null);
        self::assertStringContainsString('composer install', $installCommand);
        self::assertStringContainsString('--no-dev', $installCommand);
        self::assertStringContainsString('bin/api-guard', $compareCommand);
        self::assertStringContainsString('--old="$OLD_SPEC"', $compareCommand);
        self::assertStringContainsString('--new="$NEW_SPEC"', $compareCommand);
    }

    public function testPullRequestWorkflowUsesExactBaseAndHeadCommits(): void
    {
        $root = dirname(__DIR__, 2);
        $workflow = $this->mapping(Yaml::parseFile($root . '/docs/examples/api-guard.yml'));
        $triggers = $this->mapping($workflow['on'] ?? null);
        $permissions = $this->mapping($workflow['permissions'] ?? null);
        $jobs = $this->mapping($workflow['jobs'] ?? null);
        $job = $this->mapping($jobs['api-guard'] ?? null);
        $steps = $this->sequence($job['steps'] ?? null);
        $headCheckout = $this->mapping($steps[0] ?? null);
        $baseCheckout = $this->mapping($steps[1] ?? null);
        $actionStep = $this->mapping($steps[2] ?? null);

        self::assertArrayHasKey('pull_request', $triggers);
        self::assertSame('read', $permissions['contents'] ?? null);

        self::assertSame('${{ github.event.pull_request.head.sha }}', $this->mapping($headCheckout['with'] ?? null)['ref'] ?? null);
        self::assertSame('${{ github.event.pull_request.base.sha }}', $this->mapping($baseCheckout['with'] ?? null)['ref'] ?? null);
        self::assertSame('makaveli77/api-guard@v1', $actionStep['uses'] ?? null);
        $actionInputs = $this->mapping($actionStep['with'] ?? null);
        self::assertSame('.api-guard-baseline/docs/openapi.yaml', $actionInputs['old'] ?? null);
        self::assertSame('docs/openapi.yaml', $actionInputs['new'] ?? null);
    }

    public function testReadmeActionSnippetParsesAndMatchesPublishedActionInputs(): void
    {
        $root = dirname(__DIR__, 2);
        $readme = file_get_contents($root . '/README.md');
        if ($readme === false) {
            self::fail('Unable to read README.md.');
        }
        $matchCount = preg_match('/## GitHub Actions.*?```yaml\s*(.*?)```/s', $readme, $matches);
        if ($matchCount !== 1 || !isset($matches[1])) {
            self::fail('README GitHub Actions YAML example was not found.');
        }

        $readmeSteps = $this->sequence(Yaml::parse($matches[1]));
        $readmeStep = $this->mapping($readmeSteps[0] ?? null);
        $readmeInputs = $this->mapping($readmeStep['with'] ?? null);
        $action = $this->mapping(Yaml::parseFile($root . '/action.yml'));
        $actionInputs = $this->mapping($action['inputs'] ?? null);
        $example = $this->mapping(Yaml::parseFile($root . '/docs/examples/api-guard.yml'));
        $exampleJobs = $this->mapping($example['jobs'] ?? null);
        $exampleJob = $this->mapping($exampleJobs['api-guard'] ?? null);
        $exampleSteps = $this->sequence($exampleJob['steps'] ?? null);
        $exampleActionStep = $this->mapping($exampleSteps[2] ?? null);
        $exampleInputs = $this->mapping($exampleActionStep['with'] ?? null);

        self::assertSame($exampleActionStep['uses'] ?? null, $readmeStep['uses'] ?? null);
        foreach (['old', 'new'] as $inputName) {
            self::assertTrue($this->mapping($actionInputs[$inputName] ?? null)['required'] ?? false);
            self::assertSame($exampleInputs[$inputName] ?? null, $readmeInputs[$inputName] ?? null);
        }
    }

    public function testRepositoryCiCoversAdvertisedPhpAndFrameworkVersions(): void
    {
        $root = dirname(__DIR__, 2);
        $workflow = $this->mapping(Yaml::parseFile($root . '/.github/workflows/ci.yml'));
        $jobs = $this->mapping($workflow['jobs'] ?? null);

        foreach (['core', 'pro'] as $jobName) {
            $job = $this->mapping($jobs[$jobName] ?? null);
            $strategy = $this->mapping($job['strategy'] ?? null);
            $matrix = $this->mapping($strategy['matrix'] ?? null);
            self::assertSame(['8.2', '8.3', '8.4', '8.5'], $matrix['php'] ?? null);
        }

        $proConsoleJob = $this->mapping($jobs['pro-console'] ?? null);
        $proConsoleStrategy = $this->mapping($proConsoleJob['strategy'] ?? null);
        $proConsoleMatrix = $this->mapping($proConsoleStrategy['matrix'] ?? null);
        $proConsoleRows = $this->sequence($proConsoleMatrix['include'] ?? null);
        $proConsoleVersions = array_map(fn (mixed $row): mixed => $this->mapping($row)['symfony'] ?? null, $proConsoleRows);
        self::assertSame(['6.4', '7.0', '8.0'], $proConsoleVersions);

        $laravelJob = $this->mapping($jobs['laravel'] ?? null);
        $laravelStrategy = $this->mapping($laravelJob['strategy'] ?? null);
        $laravelMatrix = $this->mapping($laravelStrategy['matrix'] ?? null);
        $laravelRows = $this->sequence($laravelMatrix['include'] ?? null);
        $laravelVersions = array_map(fn (mixed $row): mixed => $this->mapping($row)['laravel'] ?? null, $laravelRows);
        self::assertSame(['10', '11', '12'], $laravelVersions);

        $symfonyJob = $this->mapping($jobs['symfony'] ?? null);
        $symfonyStrategy = $this->mapping($symfonyJob['strategy'] ?? null);
        $symfonyMatrix = $this->mapping($symfonyStrategy['matrix'] ?? null);
        $symfonyRows = $this->sequence($symfonyMatrix['include'] ?? null);
        $symfonyVersions = array_map(fn (mixed $row): mixed => $this->mapping($row)['symfony'] ?? null, $symfonyRows);
        self::assertSame(['6.4', '7.0', '8.0'], $symfonyVersions);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapping(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \RuntimeException('Expected a YAML mapping.');
        }

        $mapping = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \RuntimeException('Expected string keys in YAML mapping.');
            }

            $mapping[$key] = $item;
        }

        return $mapping;
    }

    /**
     * @return list<mixed>
     */
    private function sequence(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \RuntimeException('Expected a YAML sequence.');
        }

        return array_values($value);
    }

    private function stringValue(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \RuntimeException('Expected a string value in YAML.');
        }

        return $value;
    }
}