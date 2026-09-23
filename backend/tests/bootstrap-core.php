<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

// Core tests may autoload application/domain classes, never the framework or adapters.
spl_autoload_register(static function (string $class): void {
    foreach (['Illuminate\\', 'Carbon\\', 'App\\Infrastructure\\', 'App\\Http\\', 'Tests\\TestCase'] as $forbidden) {
        if (str_starts_with($class, $forbidden)) {
            throw new LogicException('The isolated core suite attempted to load '.$class);
        }
    }
}, prepend: true);
