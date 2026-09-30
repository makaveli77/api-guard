<?php

declare(strict_types=1);

namespace ApiGuard\Laravel;

use ApiGuard\Application\ComparisonService;
use ApiGuard\CLI\ComparisonReportFormatter;
use ApiGuard\Infrastructure\OpenApi\OpenApiFileLoader;
use ApiGuard\Infrastructure\OpenApi\SpecificationLoaderInterface;
use ApiGuard\Laravel\Console\CompareOpenApiCommand;
use Illuminate\Support\ServiceProvider;

final class ApiGuardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SpecificationLoaderInterface::class, OpenApiFileLoader::class);
        $this->app->singleton(ComparisonService::class);
        $this->app->singleton(ComparisonReportFormatter::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([CompareOpenApiCommand::class]);
        }
    }
}