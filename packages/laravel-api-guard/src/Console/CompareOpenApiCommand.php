<?php

declare(strict_types=1);

namespace ApiGuard\Laravel\Console;

use ApiGuard\Application\ComparisonService;
use ApiGuard\CLI\ComparisonReportFormatter;
use ApiGuard\Domain\Comparison\Change;
use ApiGuard\Domain\Comparison\Severity;
use ApiGuard\Infrastructure\OpenApi\SpecificationLoadException;
use ApiGuard\Infrastructure\OpenApi\SpecificationLoaderInterface;
use Illuminate\Console\Command;
use Throwable;

final class CompareOpenApiCommand extends Command
{
    public const EXIT_SUCCESS = 0;
    public const EXIT_BREAKING_CHANGES = 1;
    public const EXIT_INVALID_INPUT = 2;
    public const EXIT_INVALID_SPECIFICATION = 3;
    public const EXIT_UNEXPECTED_ERROR = 4;

    protected $signature = 'api:guard {old : Path to the baseline OpenAPI specification} {new : Path to the updated OpenAPI specification}';

    protected $description = 'Compare two OpenAPI specifications and report breaking changes.';

    public function handle(
        SpecificationLoaderInterface $loader,
        ComparisonService $comparisonService,
        ComparisonReportFormatter $reportFormatter,
    ): int {
        $oldPath = $this->argument('old');
        $newPath = $this->argument('new');

        if (!is_string($oldPath) || !is_string($newPath)) {
            $this->error('Both the baseline and updated specification paths are required.');

            return self::EXIT_INVALID_INPUT;
        }

        foreach ([$oldPath, $newPath] as $path) {
            if (!is_file($path)) {
                $this->error(sprintf('Input file does not exist or is not a regular file: %s', $path));

                return self::EXIT_INVALID_INPUT;
            }
            if (!is_readable($path)) {
                $this->error(sprintf('Input file is not readable: %s', $path));

                return self::EXIT_INVALID_INPUT;
            }
        }

        try {
            $oldSpecification = $loader->load($oldPath);
            $newSpecification = $loader->load($newPath);
        } catch (SpecificationLoadException $exception) {
            $this->error('Invalid OpenAPI specification: ' . $exception->getMessage());

            return self::EXIT_INVALID_SPECIFICATION;
        } catch (Throwable $exception) {
            $this->error('Unexpected error while loading specifications: ' . $exception->getMessage());

            return self::EXIT_UNEXPECTED_ERROR;
        }

        try {
            $changes = $comparisonService->compare($oldSpecification, $newSpecification);
            $this->output->write($reportFormatter->format($oldPath, $newPath, $changes));
        } catch (Throwable $exception) {
            $this->error('Unexpected error while comparing specifications: ' . $exception->getMessage());

            return self::EXIT_UNEXPECTED_ERROR;
        }

        foreach ($changes as $change) {
            if ($change->severity === Severity::BREAKING) {
                return self::EXIT_BREAKING_CHANGES;
            }
        }

        return self::EXIT_SUCCESS;
    }
}