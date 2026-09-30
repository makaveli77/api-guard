<?php

declare(strict_types=1);

namespace ApiGuard\CLI;

final class ApiGuardCommand
{
    public const string NAME = 'api-guard';

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $arguments = $argv;
        array_shift($arguments);

        if ($arguments === [] || in_array('--help', $arguments, true) || in_array('-h', $arguments, true)) {
            $this->printUsage();

            return 2;
        }

        return 2;
    }

    public function printUsage(): void
    {
        echo "API Guard\n";
        echo "\n";
        echo "Usage: vendor/bin/api-guard check --old=<file> --new=<file>\n";
        echo "\n";
        echo "Phase 1 foundation only. Comparison logic will be implemented in a later phase.\n";
    }
}
