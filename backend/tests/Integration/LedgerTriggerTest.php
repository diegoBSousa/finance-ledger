<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Accounting\Contracts\JournalRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Core\Support\Accounts;
use Tests\Core\Support\PostingBatches;
use Tests\Integration\Support\UsesMysql;

final class LedgerTriggerTest extends TestCase
{
    use UsesMysql;

    public function test_bulk_sql_insert_invalidates_every_touched_account_without_model_observers(): void
    {
        $this->persistAccounts(Accounts::data());
        $journal = DB::table('journal_entries')->insertGetId($this->journalData());
        DB::table('ledger_entries')->insert([
            $this->ledgerData($journal),
            array_replace($this->ledgerData($journal, '7002'), ['position' => 2, 'side' => 'credit']),
        ]);
        self::assertSame(2, DB::table('financial_states')->where('owner_user_id', 7)->value('revision'));
        foreach ([42, 7002] as $id) {
            $balance = DB::table('account_balances')->where('account_id', $id)->first();
            self::assertSame(1, $balance->ledger_version);
            self::assertSame(1, $balance->staled);
            self::assertSame(0, $balance->calculated_version);
        }
        self::assertSame(0, DB::table('account_balances')->where('account_id', 7001)->value('staled'));
    }

    public function test_a_missing_second_projection_rolls_back_the_entire_bulk_statement_and_first_invalidation(): void
    {
        $this->persistAccounts(Accounts::data());
        DB::table('account_balances')->where('account_id', 7002)->delete();
        $journal = DB::table('journal_entries')->insertGetId($this->journalData());
        $this->assertSqlError(1644, fn () => DB::table('ledger_entries')->insert([
            $this->ledgerData($journal),
            array_replace($this->ledgerData($journal, '7002'), ['position' => 2, 'side' => 'credit']),
        ]));
        self::assertSame(0, DB::table('ledger_entries')->count());
        self::assertSame(0, DB::table('financial_states')->where('owner_user_id', 7)->value('revision'));
        self::assertSame(0, DB::table('account_balances')->where('account_id', 42)->value('ledger_version'));
        self::assertSame(0, DB::table('account_balances')->where('account_id', 42)->value('staled'));
    }

    public function test_raw_insert_refuses_a_missing_owner_state(): void
    {
        $this->persistAccounts(Accounts::data());
        DB::table('financial_states')->where('owner_user_id', 7)->delete();
        $journal = DB::table('journal_entries')->insertGetId($this->journalData());
        $this->assertSqlError(1644, fn () => DB::table('ledger_entries')->insert($this->ledgerData($journal)));
        self::assertSame(0, DB::table('ledger_entries')->count());
        self::assertSame(0, DB::table('account_balances')->where('account_id', 42)->value('staled'));
    }

    #[DataProvider('immutableOperations')]
    public function test_sql_cannot_update_or_delete_accounting_records(string $table, string $operation, array $changes): void
    {
        $this->persistAccounts(Accounts::data());
        $this->app->make(JournalRepository::class)->post(PostingBatches::batch());
        $before = DB::table($table)->get()->all();
        $this->assertSqlError(1644, fn () => $operation === 'update'
            ? DB::table($table)->update($changes) : DB::table($table)->delete());
        self::assertEquals($before, DB::table($table)->get()->all());
        self::assertSame(2, DB::table('financial_states')->where('owner_user_id', 7)->value('revision'));
    }

    public static function immutableOperations(): iterable
    {
        yield 'change header' => ['journal_entries', 'update', ['description' => 'changed']];
        yield 'delete header' => ['journal_entries', 'delete', []];
        yield 'change posting' => ['ledger_entries', 'update', ['amount_minor' => 1]];
        yield 'delete posting' => ['ledger_entries', 'delete', []];
    }

    public function test_a_completed_operation_cannot_gain_a_third_posting(): void
    {
        $this->persistAccounts(Accounts::data());
        $result = $this->app->make(JournalRepository::class)->post(PostingBatches::batch())->entries[0];
        $this->assertSqlError(3819, fn () => DB::table('ledger_entries')->insert(array_replace(
            $this->ledgerData((int) $result->journalEntryId), ['position' => 3],
        )));
        self::assertSame(2, DB::table('ledger_entries')->count());
        self::assertSame(2, DB::table('financial_states')->where('owner_user_id', 7)->value('revision'));
    }

    private function assertSqlError(int $number, callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected a MySQL integrity error.');
        } catch (QueryException $exception) {
            self::assertSame($number, $exception->errorInfo[1], $exception->getMessage());
        }
    }
}
