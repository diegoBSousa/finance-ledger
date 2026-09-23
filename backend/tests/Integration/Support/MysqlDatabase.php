<?php

declare(strict_types=1);

namespace Tests\Integration\Support;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class MysqlDatabase
{
    public static function application(): Application
    {
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        if ($app->configurationIsCached()) {
            throw new RuntimeException('Integration tests require uncached configuration.');
        }
        $app->make(Kernel::class)->bootstrap();

        if (! $app->environment('testing')
            || getenv('MYSQL_TEST_RESET') !== '1'
            || config('database.default') !== 'mysql'
            || config('database.connections.mysql.database') !== 'finance_ledger_test') {
            throw new RuntimeException('Use MYSQL_TEST_RESET=1 and DB_DATABASE=finance_ledger_test on the disposable MySQL test service.');
        }

        $server = DB::selectOne('SELECT DATABASE() AS db, VERSION() AS version');
        if ($server->db !== 'finance_ledger_test' || ! str_starts_with($server->version, '8.4.')) {
            throw new RuntimeException('Integration tests require the finance_ledger_test database on MySQL 8.4.');
        }

        return $app;
    }

    public static function migrate(): void
    {
        $app = self::application();
        $exit = Artisan::call('migrate:fresh', ['--force' => true]);
        if ($exit !== 0) {
            throw new RuntimeException(Artisan::output());
        }
        $app->make('db')->disconnect();
        $app->flush();
        HandleExceptions::flushState();
    }
}
