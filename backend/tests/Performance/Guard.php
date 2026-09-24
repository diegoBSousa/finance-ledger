<?php

declare(strict_types=1);

namespace Tests\Performance;

use Illuminate\Foundation\Application;
use RuntimeException;

final class Guard
{
    public static function check(Application $app): void
    {
        if ($app->configurationIsCached() || ! $app->environment('testing')
            || getenv('LEDGER_PERFORMANCE_RESET') !== '1'
            || config('database.default') !== 'mysql'
            || config('database.connections.mysql.database') !== 'finance_ledger_performance'
            || config('database.connections.mysql.url') || config('database.connections.mysql.unix_socket')
            || config('queue.default') !== 'redis' || config('cache.default') !== 'redis'
            || config('database.redis.options.prefix') !== 'finance_ledger_performance:') {
            throw new RuntimeException('Performance suite requires the isolated testing database and Redis configuration. Use scripts/test-performance.sh.');
        }
    }
}
