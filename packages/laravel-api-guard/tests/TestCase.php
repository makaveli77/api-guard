<?php

declare(strict_types=1);

namespace ApiGuard\Laravel\Tests;

use ApiGuard\Laravel\ApiGuardServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [ApiGuardServiceProvider::class];
    }
}