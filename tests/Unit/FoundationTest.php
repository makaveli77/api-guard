<?php

declare(strict_types=1);

namespace ApiGuard\Tests\Unit;

use ApiGuard\CLI\ApiGuardCommand;
use ApiGuard\Domain\Comparison\Change;
use ApiGuard\Domain\Comparison\ChangeType;
use ApiGuard\Domain\Comparison\Severity;
use PHPUnit\Framework\TestCase;

final class FoundationTest extends TestCase
{
    public function testChangeModelCanBeCreated(): void
    {
        $change = new Change(
            ChangeType::PATH,
            Severity::BREAKING,
            '/users',
            'Endpoint removed.'
        );

        $this->assertSame(ChangeType::PATH, $change->type);
        $this->assertSame(Severity::BREAKING, $change->severity);
        $this->assertSame('/users', $change->path);
        $this->assertSame('Endpoint removed.', $change->message);
    }

    public function testCliCommandHasRunMethod(): void
    {
        $command = new ApiGuardCommand();

        ob_start();
        $exitCode = $command->run(['api-guard', '--help']);
        $output = (string) ob_get_clean();

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('Usage: vendor/bin/api-guard check', $output);
    }
}
