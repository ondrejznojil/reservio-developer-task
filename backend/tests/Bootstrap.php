<?php

declare(strict_types=1);

namespace Tests;

use Nette\Bootstrap\Configurator;
use Nette\DI\Container;

final class Bootstrap
{
    public static function boot(): Container
    {
        $tempDir = __DIR__ . '/../temp/tests/integration';
        if (!is_dir($tempDir)) {
            mkdir(directory: $tempDir, permissions: 0o777, recursive: true);
        }

        $configurator = new Configurator();
        $configurator->setDebugMode(false);
        $configurator->setTempDirectory($tempDir);
        $configurator->addConfig(__DIR__ . '/../config/config.neon');
        $configurator->addConfig(__DIR__ . '/config/config.test.neon');

        return $configurator->createContainer();
    }
}
