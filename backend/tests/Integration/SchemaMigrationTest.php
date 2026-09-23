<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;
use Tests\Integration\Support\MysqlDatabase;

final class SchemaMigrationTest extends TestCase
{
    public function test_installing_triggers_marks_preexisting_sql_postings_as_stale(): void
    {
        $app = MysqlDatabase::application();
        $migration = require database_path('migrations/2026_09_23_000006_protect_ledger_and_invalidate_balances.php');
        try {
            $migration->down();
            $owner = DB::table('users')->insertGetId(['name' => 'Upgrade', 'email' => 'upgrade@example.test', 'password' => 'unused']);
            DB::table('financial_states')->insert(['owner_user_id' => $owner]);
            $account = DB::table('accounts')->insertGetId(['owner_user_id' => $owner, 'external_number' => 682, 'kind' => 'asset']);
            $technical = DB::table('accounts')->insertGetId(['owner_user_id' => $owner, 'kind' => 'expense']);
            DB::table('account_balances')->insert([['account_id' => $account], ['account_id' => $technical]]);
            $journal = DB::table('journal_entries')->insertGetId([
                'owner_user_id' => $owner, 'uploaded_by_user_id' => $owner, 'financial_account_id' => $account,
                'posting_date' => '2026-08-16', 'description' => 'Legacy', 'original_description' => 'Legacy #682',
                'movement_type' => 'expense', 'source_row_hash' => hash('sha256', 'legacy'), 'canonical_record' => '["legacy"]',
            ]);
            DB::table('ledger_entries')->insert([
                ['journal_entry_id' => $journal, 'owner_user_id' => $owner, 'account_id' => $technical, 'position' => 1, 'side' => 'debit', 'amount_minor' => 10],
                ['journal_entry_id' => $journal, 'owner_user_id' => $owner, 'account_id' => $account, 'position' => 2, 'side' => 'credit', 'amount_minor' => 10],
            ]);
            self::assertSame(0, DB::table('financial_states')->where('owner_user_id', $owner)->value('revision'));
            $migration->up();
            self::assertSame(2, DB::table('financial_states')->where('owner_user_id', $owner)->value('revision'));
            foreach ([$account, $technical] as $id) {
                $balance = DB::table('account_balances')->where('account_id', $id)->first();
                self::assertSame(1, $balance->staled);
                self::assertSame(1, $balance->ledger_version);
                self::assertSame(0, $balance->calculated_version);
            }
        } finally {
            $app->make('db')->disconnect();
            $app->flush();
            HandleExceptions::flushState($this);
            MysqlDatabase::migrate($this);
        }
    }

    public function test_migrations_can_be_rolled_back_and_reapplied_on_the_disposable_database(): void
    {
        // DDL commits implicitly on MySQL: deliberately run outside the per-test transaction trait.
        $app = MysqlDatabase::application();
        try {
            self::assertSame(0, Artisan::call('migrate:rollback', ['--force' => true]));
            self::assertFalse(Schema::hasTable('accounts'));
            self::assertFalse(Schema::hasTable('ledger_entries'));
            self::assertSame(0, Artisan::call('migrate', ['--force' => true]));
            foreach (['accounts', 'account_balances', 'financial_states', 'journal_entries', 'ledger_entries', 'imports', 'import_rows', 'outbox_events', 'outbox_deliveries', 'revoked_tokens'] as $table) {
                self::assertTrue(Schema::hasTable($table), $table);
            }
            self::assertSame(5, DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', 'finance_ledger_test')->count());
        } finally {
            $app->make('db')->disconnect();
            $app->flush();
            HandleExceptions::flushState($this);
        }
    }
}
