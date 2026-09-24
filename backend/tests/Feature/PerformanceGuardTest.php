<?php

declare(strict_types=1);

namespace Tests\Feature;

use RuntimeException;
use Tests\Performance\Guard;
use Tests\TestCase;

final class PerformanceGuardTest extends TestCase
{
    public function test_default_test_environment_cannot_start_the_destructive_performance_suite(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('isolated testing database');

        Guard::check($this->app);
    }

    public function test_even_explicit_reset_refuses_the_development_database(): void
    {
        $previous = getenv('LEDGER_PERFORMANCE_RESET');
        putenv('LEDGER_PERFORMANCE_RESET=1');
        config(['database.connections.mysql.database' => 'finance_ledger', 'database.connections.mysql.url' => '',
            'database.connections.mysql.unix_socket' => '', 'database.default' => 'mysql',
            'queue.default' => 'redis', 'cache.default' => 'redis',
            'database.redis.options.prefix' => 'finance_ledger_performance:']);
        try {
            $this->expectException(RuntimeException::class);
            Guard::check($this->app);
        } finally {
            putenv($previous === false ? 'LEDGER_PERFORMANCE_RESET' : 'LEDGER_PERFORMANCE_RESET='.$previous);
        }
    }
}
