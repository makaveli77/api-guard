<?php

declare(strict_types=1);

namespace ApiGuard\CLI;

use ApiGuard\Application\ComparisonService;
use ApiGuard\Domain\Comparison\Severity;
use ApiGuard\Infrastructure\OpenApi\OpenApiFileLoader;
use ApiGuard\Infrastructure\OpenApi\SpecificationLoadException;
use ApiGuard\Infrastructure\OpenApi\SpecificationLoaderInterface;
use InvalidArgumentException;
use Throwable;

final class ApiGuardCommand
{
    public const NAME = 'api-guard';

    public const EXIT_SUCCESS = 0;
    public const EXIT_BREAKING_CHANGES = 1;
    public const EXIT_INVALID_INPUT = 2;
    public const EXIT_INVALID_SPECIFICATION = 3;
    public const EXIT_UNEXPECTED_ERROR = 4;

    private readonly SpecificationLoaderInterface $loader;

    private readonly ComparisonService $comparisonService;

    private readonly ComparisonReportFormatter $reportFormatter;

    public function __construct(
        ?SpecificationLoaderInterface $loader = null,
        ?ComparisonService $comparisonService = null,
        ?ComparisonReportFormatter $reportFormatter = null,
    ) {
        $this->loader = $loader ?? new OpenApiFileLoader();
        $this->comparisonService = $comparisonService ?? new ComparisonService();
        $this->reportFormatter = $reportFormatter ?? new ComparisonReportFormatter();
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $arguments = array_slice($argv, 1);

        if ($this->isHelpRequest($arguments)) {
            $this->printUsage();

            return self::EXIT_SUCCESS;
        }

        try {
            $files = $this->parseArguments($arguments);
        } catch (InvalidArgumentException $exception) {
            $this->writeError('Invalid arguments: ' . $exception->getMessage());
            $this->printUsage();

            return self::EXIT_INVALID_INPUT;
        }

        try {
            $this->validateInputFile($files['old']);
            $this->validateInputFile($files['new']);
        } catch (InvalidArgumentException $exception) {
            $this->writeError($exception->getMessage());

            return self::EXIT_INVALID_INPUT;
        }

        try {
            $oldSpecification = $this->loader->load($files['old']);
            $newSpecification = $this->loader->load($files['new']);
        } catch (SpecificationLoadException $exception) {
            $this->writeError('Invalid OpenAPI specification: ' . $exception->getMessage());

            return self::EXIT_INVALID_SPECIFICATION;
        } catch (Throwable $exception) {
            $this->writeError('Unexpected error while loading specifications: ' . $exception->getMessage());

            return self::EXIT_UNEXPECTED_ERROR;
        }

        try {
            $changes = $this->comparisonService->compare($oldSpecification, $newSpecification);
            echo $this->reportFormatter->format($files['old'], $files['new'], $changes);
        } catch (Throwable $exception) {
            $this->writeError('Unexpected error while comparing specifications: ' . $exception->getMessage());

            return self::EXIT_UNEXPECTED_ERROR;
        }

        foreach ($changes as $change) {
            if ($change->severity === Severity::BREAKING) {
                return self::EXIT_BREAKING_CHANGES;
            }
        }

        return self::EXIT_SUCCESS;
    }

    public function printUsage(): void
    {
        echo "API Guard\n";
        echo "\n";
        echo "Usage: vendor/bin/api-guard check --old=<file> --new=<file>\n";
        echo "       vendor/bin/api-guard --help\n";
    }

    /**
     * @param list<string> $arguments
     */
    private function isHelpRequest(array $arguments): bool
    {
        return $arguments === ['--help']
            || $arguments === ['-h']
            || $arguments === ['check', '--help']
            || $arguments === ['check', '-h'];
    }

    /**
     * @param list<string> $arguments
     * @return array{old: string, new: string}
     */
    private function parseArguments(array $arguments): array
    {
        if (($arguments[0] ?? null) !== 'check') {
            throw new InvalidArgumentException('expected the "check" command.');
        }

        $files = [];
        foreach (array_slice($arguments, 1) as $argument) {
            if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
                throw new InvalidArgumentException(sprintf('unexpected argument "%s".', $argument));
            }

            [$option, $value] = explode('=', substr($argument, 2), 2);
            if (!in_array($option, ['old', 'new'], true)) {
                throw new InvalidArgumentException(sprintf('unknown option "--%s".', $option));
            }
            if (array_key_exists($option, $files)) {
                throw new InvalidArgumentException(sprintf('option "--%s" was provided more than once.', $option));
            }
            if (trim($value) === '') {
                throw new InvalidArgumentException(sprintf('option "--%s" requires a file path.', $option));
            }

            $files[$option] = $value;
        }

        if (!isset($files['old'], $files['new'])) {
            throw new InvalidArgumentException('both --old=<file> and --new=<file> are required.');
        }

        return ['old' => $files['old'], 'new' => $files['new']];
    }

    private function validateInputFile(string $path): void
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException(sprintf('Input file does not exist or is not a regular file: %s', $path));
        }

        if (!is_readable($path)) {
            throw new InvalidArgumentException(sprintf('Input file is not readable: %s', $path));
        }
    }

    private function writeError(string $message): void
    {
        fwrite(STDERR, 'Error: ' . $message . PHP_EOL);
    }
}
