<?php

declare(strict_types=1);

namespace ApiGuard\Pro\Console;

use ApiGuard\CLI\ComparisonReportFormatter;
use ApiGuard\Pro\Application\ProComparisonResult;
use ApiGuard\Infrastructure\OpenApi\SpecificationLoadException;
use ApiGuard\Pro\Application\ProComparisonService;
use ApiGuard\Pro\Ignore\IgnoreConfigurationException;
use ApiGuard\Pro\Report\JsonReportFormatter;
use ApiGuard\Pro\Report\MarkdownReportFormatter;
use ApiGuard\Pro\Report\ReportFormat;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

final class CheckCommand extends Command
{
    public const EXIT_SUCCESS = 0;
    public const EXIT_BREAKING_CHANGES = 1;
    public const EXIT_INVALID_INPUT = 2;
    public const EXIT_INVALID_SPECIFICATION = 3;
    public const EXIT_UNEXPECTED_ERROR = 4;

    public function __construct(
        private readonly ProComparisonService $comparisonService,
        private readonly JsonReportFormatter $jsonReportFormatter,
        private readonly MarkdownReportFormatter $markdownReportFormatter,
        private readonly ComparisonReportFormatter $textReportFormatter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('check')
            ->setDescription('Compare OpenAPI specifications with Pro policies and report formats.')
            ->addOption('old', null, InputOption::VALUE_REQUIRED, 'Path to the baseline OpenAPI specification')
            ->addOption('new', null, InputOption::VALUE_REQUIRED, 'Path to the updated OpenAPI specification')
            ->addOption('ignore-config', null, InputOption::VALUE_REQUIRED, 'Path to a versioned ignore-rules YAML file')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Report format: text, json, or markdown', ReportFormat::TEXT->value);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $oldPath = $input->getOption('old');
        $newPath = $input->getOption('new');
        $ignoreConfigPath = $input->getOption('ignore-config');
        $formatOption = $input->getOption('format');
        $format = is_string($formatOption) ? ReportFormat::tryFrom($formatOption) : null;

        if (!is_string($oldPath) || !is_string($newPath) || ($ignoreConfigPath !== null && !is_string($ignoreConfigPath))) {
            $io->error('Both specification paths and a valid ignore configuration path are required.');

            return self::EXIT_INVALID_INPUT;
        }
        if ($format === null) {
            $io->error('Unknown report format. Use text, json, or markdown.');

            return self::EXIT_INVALID_INPUT;
        }
        foreach ([$oldPath, $newPath] as $path) {
            if (!is_file($path) || !is_readable($path)) {
                $io->error(sprintf('Input file does not exist or is not readable: %s', $path));

                return self::EXIT_INVALID_INPUT;
            }
        }

        try {
            $result = $this->comparisonService->compareFiles($oldPath, $newPath, $ignoreConfigPath);
        } catch (IgnoreConfigurationException|InvalidArgumentException $exception) {
            $io->error('Invalid ignore configuration: ' . $exception->getMessage());

            return self::EXIT_INVALID_INPUT;
        } catch (SpecificationLoadException $exception) {
            $io->error('Invalid OpenAPI specification: ' . $exception->getMessage());

            return self::EXIT_INVALID_SPECIFICATION;
        } catch (Throwable $exception) {
            $io->error('Unexpected error while comparing specifications: ' . $exception->getMessage());

            return self::EXIT_UNEXPECTED_ERROR;
        }

        try {
            $report = match ($format) {
                ReportFormat::TEXT => $this->formatText($oldPath, $newPath, $result),
                ReportFormat::JSON => $this->jsonReportFormatter->format($oldPath, $newPath, $result),
                ReportFormat::MARKDOWN => $this->markdownReportFormatter->format($oldPath, $newPath, $result),
            };
        } catch (Throwable $exception) {
            $io->error('Unable to format comparison report: ' . $exception->getMessage());

            return self::EXIT_UNEXPECTED_ERROR;
        }
        $output->write($report);

        return $result->hasBreakingChanges() ? self::EXIT_BREAKING_CHANGES : self::EXIT_SUCCESS;
    }

    private function formatText(
        string $oldPath,
        string $newPath,
        ProComparisonResult $result,
    ): string {
        $report = $this->textReportFormatter->format($oldPath, $newPath, $result->changes);
        if ($result->ignoredChanges === []) {
            return $report;
        }

        $lines = [rtrim($report), '', sprintf('IGNORED CHANGES (%d)', count($result->ignoredChanges))];
        foreach ($result->ignoredChanges as $ignoredChange) {
            $lines[] = sprintf('  - %s [%s]', $ignoredChange->change->path, $ignoredChange->change->type->value);
            $lines[] = '    ' . $ignoredChange->change->message;
            $lines[] = '    Reason: ' . $ignoredChange->reason;
        }

        return implode("\n", $lines) . "\n";
    }
}