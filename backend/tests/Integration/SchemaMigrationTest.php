<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;
use Tests\Integration\Support\MysqlDatabase;

final class SchemaMigrationTest extends TestCase
{
    public function test_migrations_can_be_rolled_back_and_reapplied_on_the_disposable_database(): void
    {
        // DDL commits implicitly on MySQL: deliberately run outside the per-test transaction trait.
        $app = MysqlDatabase::application();
        try {
            self::assertSame(0, Artisan::call('migrate:rollback', ['--force' => true]));
            self::assertFalse(Schema::hasTable('accounts'));
            self::assertFalse(Schema::hasTable('ledger_entries'));
            self::assertSame(0, Artisan::call('migrate', ['--force' => true]));
            foreach (['accounts', 'account_balances', 'financial_states', 'journal_entries', 'ledger_entries', 'imports', 'import_rows', 'outbox_events', 'outbox_deliveries'] as $table) {
                self::assertTrue(Schema::hasTable($table), $table);
            }
        } finally {
            $app->make('db')->disconnect();
            $app->flush();
            HandleExceptions::flushState($this);
        }
    }
}
