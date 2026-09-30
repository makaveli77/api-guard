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