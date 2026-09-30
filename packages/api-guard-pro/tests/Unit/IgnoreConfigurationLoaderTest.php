<?php

declare(strict_types=1);

namespace ApiGuard\Pro\Tests\Unit;

use ApiGuard\Domain\Comparison\Change;
use ApiGuard\Domain\Comparison\ChangeType;
use ApiGuard\Domain\Comparison\Severity;
use ApiGuard\Pro\Ignore\IgnoreConfigurationException;
use ApiGuard\Pro\Ignore\IgnoreConfigurationLoader;
use PHPUnit\Framework\TestCase;

final class IgnoreConfigurationLoaderTest extends TestCase
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

    public function testMissingConfigUsesEmptyPolicy(): void
    {
        self::assertSame([], (new IgnoreConfigurationLoader())->load(null)->rules);
    }

    public function testVersionOneConfigLoadsTypedPathAndOptionalExactMessage(): void
    {
        $configuration = <<<'YAML'
version: 1
ignore:
  - type: response_schema
    path: /users/{id}
    message: GET response 200 application/json property email was removed.
    reason: Approved for the v2 migration window.
YAML;
        $path = $this->writeConfiguration($configuration);

        $policy = (new IgnoreConfigurationLoader())->load($path);

        self::assertCount(1, $policy->rules);
        self::assertTrue($policy->rules[0]->matches(new Change(
            ChangeType::RESPONSE_SCHEMA,
            Severity::BREAKING,
            '/users/{id}',
            'GET response 200 application/json property email was removed.'
        )));
        self::assertFalse($policy->rules[0]->matches(new Change(
            ChangeType::RESPONSE_SCHEMA,
            Severity::BREAKING,
            '/users/{id}',
            'GET response 200 application/json property name was removed.'
        )));
    }

    public function testPathAndTypeMustBothMatchIgnoreRule(): void
    {
        $path = $this->writeConfiguration(<<<'YAML'
version: 1
ignore:
  - type: path
    path: /legacy
    reason: Scheduled retirement.
YAML);
        $policy = (new IgnoreConfigurationLoader())->load($path);

        self::assertNotNull($policy->matchingRule(new Change(ChangeType::PATH, Severity::BREAKING, '/legacy', 'Endpoint removed.')));
        self::assertNull($policy->matchingRule(new Change(ChangeType::PATH, Severity::BREAKING, '/current', 'Endpoint removed.')));
        self::assertNull($policy->matchingRule(new Change(ChangeType::METHOD, Severity::BREAKING, '/legacy', 'GET method removed.')));
    }

    public function testInvalidConfigsFailClosed(): void
    {
        $invalidConfigurations = [
            "version: 2\nignore: []\n",
            "version: 1\nignore:\n  - type: unsupported\n    path: /users\n    reason: Approved\n",
            "version: 1\nignore:\n  - type: path\n    path: /users\n",
            "version: 1\nignore:\n  - type: path\n    path: users\n    reason: Approved\n",
            "version: 1\nignore: []\nother: true\n",
            "version: [\n",
        ];

        foreach ($invalidConfigurations as $configuration) {
            $path = $this->writeConfiguration($configuration);
            try {
                (new IgnoreConfigurationLoader())->load($path);
            } catch (IgnoreConfigurationException $exception) {
                self::assertNotSame('', $exception->getMessage());
                continue;
            }

            self::fail('Expected invalid ignore configuration to be rejected.');
        }
    }

    private function writeConfiguration(string $configuration): string
    {
        $path = tempnam(sys_get_temp_dir(), 'api-guard-pro-ignore-');
        if ($path === false) {
            self::fail('Unable to create a temporary ignore configuration.');
        }

        $this->temporaryFiles[] = $path;
        file_put_contents($path, $configuration);

        return $path;
    }
}