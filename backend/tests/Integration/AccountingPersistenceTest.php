<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Accounting\Contracts\AccountRepository;
use App\Application\Accounting\Data\PrepareCsvPostingRequest;
use App\Application\Accounting\PrepareCsvPostingUseCase;
use App\Application\Imports\Data\CsvRowData;
use App\Domain\Accounting\AccountKind;
use App\Domain\Accounting\Data\AccountData;
use App\Infrastructure\Persistence\AccountProvisioner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Core\Support\Accounts;
use Tests\Integration\Support\UsesMysql;

final class AccountingPersistenceTest extends TestCase
{
    use UsesMysql;

    public function test_container_connects_the_existing_use_case_to_mysql_without_exposing_records(): void
    {
        $this->persistAccounts(Accounts::data());
        $response = $this->app->make(PrepareCsvPostingUseCase::class)->execute(new PrepareCsvPostingRequest(
            '7', new CsvRowData('2026-08-16', 'Serviços de Limpeza #682', '494618', 'Despesa'),
        ));

        self::assertSame('42', $response->financialAccountId);
        self::assertSame('d4862cdd783f053103511bf4f49bfb5438fce54b40bbabc58163b424e1136a8b', $response->sourceRowHash);
        self::assertSame('7002', $response->journal->postings[0]->accountId);
        self::assertSame('494618', $response->journal->postings[1]->amountMinor);
        self::assertSame(0, DB::table('journal_entries')->count());
    }

    public function test_repository_preserves_big_integer_identifiers_as_strings(): void
    {
        $data = new AccountData('9007199254740993', '7', '9223372036854775807', 'asset');
        $this->persistAccounts([$data]);

        self::assertEquals($data, $this->app->make(AccountRepository::class)->findFinancialByNumber($data->externalNumber));
    }

    public function test_provisioning_creates_account_state_and_zero_projection_together(): void
    {
        $this->createUser();
        $account = $this->app->make(AccountProvisioner::class)->create('7', AccountKind::Asset, '682');
        $balance = DB::table('account_balances')->where('account_id', $account->id)->first();

        self::assertSame('682', $account->externalNumber);
        self::assertSame(0, $balance->balance_minor);
        self::assertSame(0, $balance->staled);
        self::assertSame(0, $balance->ledger_version);
        self::assertSame(0, $balance->calculated_version);
        self::assertNotNull($balance->calculated_at);
        self::assertSame(0, DB::table('financial_states')->where('owner_user_id', '7')->value('revision'));
    }

    public function test_failed_provisioning_rolls_back_new_financial_state(): void
    {
        $this->createUser();
        try {
            $this->app->make(AccountProvisioner::class)->create('7', AccountKind::Revenue, '682');
            self::fail('A technical account cannot have an external number.');
        } catch (QueryException $exception) {
            self::assertSame(3819, $exception->errorInfo[1]);
        }
        self::assertSame(0, DB::table('accounts')->count());
        self::assertSame(0, DB::table('account_balances')->count());
        self::assertSame(0, DB::table('financial_states')->count());
    }

    #[DataProvider('invalidAccountAttributes')]
    public function test_mysql_rejects_invalid_account_rows(array $changes, int $error): void
    {
        $this->persistAccounts(Accounts::data());
        $this->expectSqlError($error, fn () => DB::table('accounts')->insert(array_replace([
            'owner_user_id' => 7, 'external_number' => 900, 'kind' => 'asset',
        ], $changes)));
    }

    public static function invalidAccountAttributes(): iterable
    {
        yield 'number already assigned to another owner' => [['owner_user_id' => 8, 'external_number' => 682], 1062];
        yield 'second technical account of same kind' => [['external_number' => null, 'kind' => 'revenue'], 1062];
        yield 'asset without number' => [['external_number' => null], 3819];
        yield 'technical with number' => [['kind' => 'expense'], 3819];
        yield 'zero number' => [['external_number' => 0], 3819];
        yield 'unknown kind' => [['kind' => 'unknown'], 3819];
        yield 'currency other than BRL' => [['currency' => 'USD'], 3819];
        yield 'invalid boolean' => [['active' => 2], 3819];
        yield 'missing owner' => [['owner_user_id' => 9999], 1452];
    }

    public function test_source_hash_is_unique_per_owner_across_imports(): void
    {
        $this->persistAccounts(Accounts::data());
        DB::table('journal_entries')->insert($this->journalData());
        DB::table('journal_entries')->insert($this->journalData(owner: '8', account: '43'));
        self::assertSame(2, DB::table('journal_entries')->count());

        $this->expectSqlError(1062, fn () => DB::table('journal_entries')->insert($this->journalData()));
    }

    public function test_journal_cannot_target_another_owners_account(): void
    {
        $this->persistAccounts(Accounts::data());
        $this->expectSqlError(1452, fn () => DB::table('journal_entries')->insert($this->journalData(account: '43')));
    }

    #[DataProvider('invalidPostingAttributes')]
    public function test_mysql_rejects_invalid_postings(array $changes, int $error): void
    {
        $this->persistAccounts(Accounts::data());
        $journal = DB::table('journal_entries')->insertGetId($this->journalData());
        $this->expectSqlError($error, fn () => DB::table('ledger_entries')->insert(array_replace($this->ledgerData($journal), $changes)));
    }

    public static function invalidPostingAttributes(): iterable
    {
        yield 'zero cents' => [['amount_minor' => 0], 3819];
        yield 'negative cents' => [['amount_minor' => -1], 3819];
        yield 'signed bigint overflow' => [['amount_minor' => '9223372036854775808'], 1264];
        yield 'unknown side' => [['side' => 'other'], 3819];
        yield 'zero position' => [['position' => 0], 3819];
        yield 'missing account' => [['account_id' => 99999], 1452];
        yield 'account of another owner' => [['account_id' => 43], 1452];
        yield 'journal of another owner' => [['owner_user_id' => 8, 'account_id' => 43], 1452];
        yield 'missing journal' => [['journal_entry_id' => 99999], 1452];
    }

    public function test_posting_position_cannot_be_repeated_and_referenced_journal_cannot_be_deleted(): void
    {
        $this->persistAccounts(Accounts::data());
        $journal = DB::table('journal_entries')->insertGetId($this->journalData());
        DB::table('ledger_entries')->insert($this->ledgerData($journal));
        $this->expectSqlError(1062, fn () => DB::table('ledger_entries')->insert($this->ledgerData($journal, '7002')));
        $this->expectSqlError(1451, fn () => DB::table('journal_entries')->where('id', $journal)->delete());
    }

    public function test_cents_and_negative_balances_round_trip_without_floating_point(): void
    {
        $this->persistAccounts(Accounts::data());
        $journal = DB::table('journal_entries')->insertGetId($this->journalData());
        DB::table('ledger_entries')->insert(array_replace($this->ledgerData($journal), ['amount_minor' => PHP_INT_MAX]));
        DB::table('account_balances')->where('account_id', 42)->update([
            'credit_total_minor' => PHP_INT_MAX,
            'balance_minor' => -PHP_INT_MAX,
        ]);

        self::assertSame(PHP_INT_MAX, DB::table('ledger_entries')->value('amount_minor'));
        self::assertSame(-PHP_INT_MAX, DB::table('account_balances')->where('account_id', 42)->value('balance_minor'));
    }

    #[DataProvider('invalidProjectionAttributes')]
    public function test_mysql_rejects_invalid_balance_versions_and_totals(array $changes): void
    {
        $this->persistAccounts(Accounts::data());
        $this->expectSqlError(3819, fn () => DB::table('account_balances')->where('account_id', 42)->update($changes));
    }

    public static function invalidProjectionAttributes(): iterable
    {
        yield 'negative debit total' => [['debit_total_minor' => -1]];
        yield 'negative credit total' => [['credit_total_minor' => -1]];
        yield 'invalid boolean' => [['staled' => 2]];
        yield 'calculated version ahead of ledger' => [['calculated_version' => 1]];
        yield 'fresh projection with different versions' => [['ledger_version' => 1, 'staled' => 0]];
    }

    public function test_sql_transaction_rolls_back_header_postings_and_event_when_a_posting_fails(): void
    {
        $this->persistAccounts(Accounts::data());
        $this->expectSqlError(3819, function (): void {
            DB::transaction(function (): void {
                $journal = DB::table('journal_entries')->insertGetId($this->journalData());
                DB::table('outbox_events')->insert(['event_type' => 'test.posted', 'event_version' => 1, 'payload' => '{}']);
                DB::table('ledger_entries')->insert($this->ledgerData($journal));
                DB::table('ledger_entries')->insert(array_replace($this->ledgerData($journal, '7002'), [
                    'position' => 2, 'side' => 'credit', 'amount_minor' => 0,
                ]));
            });
        });

        self::assertSame(0, DB::table('journal_entries')->count());
        self::assertSame(0, DB::table('ledger_entries')->count());
        self::assertSame(0, DB::table('outbox_events')->count());
    }

    private function expectSqlError(int $error, callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected MySQL constraint error '.$error);
        } catch (QueryException $exception) {
            self::assertSame($error, $exception->errorInfo[1], $exception->getMessage());
        }
    }
}
