<?php

declare(strict_types=1);

namespace ApiGuard\Pro\Tests\Unit;

use JsonException;
use PHPUnit\Framework\TestCase;

final class PackageConfigurationTest extends TestCase
{
    /** @throws JsonException */
    public function testComposerExposesAnExecutableProBinary(): void
    {
        $packageRoot = dirname(__DIR__, 2);
        $contents = file_get_contents($packageRoot . '/composer.json');
        if ($contents === false) {
            self::fail('Unable to read the Pro Composer manifest.');
        }

        $composer = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        self::assertSame(['bin/api-guard-pro'], $composer['bin'] ?? null);
        self::assertFileExists($packageRoot . '/bin/api-guard-pro');
        self::assertTrue(is_executable($packageRoot . '/bin/api-guard-pro'));
    }
}