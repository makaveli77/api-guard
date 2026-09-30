<?php

declare(strict_types=1);

use ApiGuard\Application\ComparisonService;
use ApiGuard\CLI\ComparisonReportFormatter;
use ApiGuard\Infrastructure\OpenApi\OpenApiFileLoader;
use ApiGuard\Infrastructure\OpenApi\SpecificationLoaderInterface;
use ApiGuard\Symfony\Console\CompareOpenApiCommand;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();

    $services->set(OpenApiFileLoader::class);
    $services->alias(SpecificationLoaderInterface::class, OpenApiFileLoader::class);
    $services->set(ComparisonService::class);
    $services->set(ComparisonReportFormatter::class);
    $services->set(CompareOpenApiCommand::class)->tag('console.command');
};