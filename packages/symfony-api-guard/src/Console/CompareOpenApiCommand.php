<?php

declare(strict_types=1);

namespace ApiGuard\Symfony\Console;

use ApiGuard\Application\ComparisonService;
use ApiGuard\CLI\ComparisonReportFormatter;
use ApiGuard\Domain\Comparison\Severity;
use ApiGuard\Infrastructure\OpenApi\SpecificationLoadException;
use ApiGuard\Infrastructure\OpenApi\SpecificationLoaderInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

final class CompareOpenApiCommand extends Command
{
    public const EXIT_SUCCESS = 0;
    public const EXIT_BREAKING_CHANGES = 1;
    public const EXIT_INVALID_INPUT = 2;
    public const EXIT_INVALID_SPECIFICATION = 3;
    public const EXIT_UNEXPECTED_ERROR = 4;

    public function __construct(
        private readonly SpecificationLoaderInterface $loader,
        private readonly ComparisonService $comparisonService,
        private readonly ComparisonReportFormatter $reportFormatter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('api-guard:check')
            ->setDescription('Compare OpenAPI specifications and report breaking changes.')
            ->addArgument('old', InputArgument::REQUIRED, 'Path to the baseline OpenAPI specification')
            ->addArgument('new', InputArgument::REQUIRED, 'Path to the updated OpenAPI specification');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $oldPath = $input->getArgument('old');
        $newPath = $input->getArgument('new');

        if (!is_string($oldPath) || !is_string($newPath)) {
            $io->error('Both the baseline and updated specification paths are required.');

            return self::EXIT_INVALID_INPUT;
        }

        foreach ([$oldPath, $newPath] as $path) {
            if (!is_file($path)) {
                $io->error(sprintf('Input file does not exist or is not a regular file: %s', $path));

                return self::EXIT_INVALID_INPUT;
            }
            if (!is_readable($path)) {
                $io->error(sprintf('Input file is not readable: %s', $path));

                return self::EXIT_INVALID_INPUT;
            }
        }

        try {
            $oldSpecification = $this->loader->load($oldPath);
            $newSpecification = $this->loader->load($newPath);
        } catch (SpecificationLoadException $exception) {
            $io->error('Invalid OpenAPI specification: ' . $exception->getMessage());

            return self::EXIT_INVALID_SPECIFICATION;
        } catch (Throwable $exception) {
            $io->error('Unexpected error while loading specifications: ' . $exception->getMessage());

            return self::EXIT_UNEXPECTED_ERROR;
        }

        try {
            $changes = $this->comparisonService->compare($oldSpecification, $newSpecification);
            $output->write($this->reportFormatter->format($oldPath, $newPath, $changes));
        } catch (Throwable $exception) {
            $io->error('Unexpected error while comparing specifications: ' . $exception->getMessage());

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