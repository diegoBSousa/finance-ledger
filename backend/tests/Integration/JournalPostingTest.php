<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Accounting\Contracts\JournalRepository;
use App\Application\Accounting\Data\PostCsvBatchRequest;
use App\Application\Accounting\Data\PostingBatchData;
use App\Application\Accounting\PostCsvBatchUseCase;
use App\Application\Accounting\PostingUnavailable;
use App\Application\Imports\Data\CsvRowData;
use App\Domain\Shared\DomainViolation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Core\Support\Accounts;
use Tests\Core\Support\PostingBatches;
use Tests\Integration\Support\UsesMysql;

final class JournalPostingTest extends TestCase
{
    use UsesMysql;

    public function test_batch_persists_balanced_operations_and_one_durable_event_without_redis(): void
    {
        $this->persistAccounts(Accounts::data());
        $response = $this->app->make(PostCsvBatchUseCase::class)->execute(new PostCsvBatchRequest('7', [
            new CsvRowData('2026-08-16', 'Entrada #682', '494618', 'Receita'),
            new CsvRowData('2026-08-16', 'Saída #682', '494618', 'Despesa'),
        ]));
        self::assertSame(['inserted', 'inserted'], array_column($response->entries, 'status'));
        self::assertSame(2, DB::table('journal_entries')->count());
        self::assertSame(4, DB::table('ledger_entries')->count());
        $sums = DB::table('ledger_entries')->selectRaw('side, SUM(amount_minor) AS total')->groupBy('side')->pluck('total', 'side')->all();
        ksort($sums);
        self::assertSame(['credit' => '989236', 'debit' => '989236'], $sums);
        self::assertSame(4, DB::table('financial_states')->where('owner_user_id', 7)->value('revision'));
        self::assertSame(0, DB::table('financial_states')->where('owner_user_id', 8)->value('revision'));
        foreach (['42' => 2, '7001' => 1, '7002' => 1] as $account => $version) {
            $balance = DB::table('account_balances')->where('account_id', $account)->first();
            self::assertSame(1, $balance->staled);
            self::assertSame($version, $balance->ledger_version);
            self::assertSame(0, $balance->calculated_version);
            self::assertSame(0, $balance->debit_total_minor);
            self::assertSame(0, $balance->balance_minor);
        }
        self::assertSame(0, DB::table('account_balances')->where('account_id', 43)->value('staled'));
        $event = DB::table('outbox_events')->sole();
        self::assertSame('LedgerChanged', $event->event_type);
        self::assertSame(1, $event->event_version);
        $expected = [
            'owner_user_id' => '7', 'currency' => 'BRL', 'journal_entry_ids' => array_column($response->entries, 'journalEntryId'),
            'account_ids' => ['42', '7001', '7002'], 'financial_revision' => '4',
        ];
        $payload = json_decode($event->payload, true, flags: JSON_THROW_ON_ERROR);
        ksort($expected);
        ksort($payload);
        self::assertSame($expected, $payload);
        $delivery = DB::table('outbox_deliveries')->sole();
        self::assertSame($event->id, $delivery->event_id);
        self::assertSame('dashboard-cache-invalidator', $delivery->consumer);
        self::assertSame('pending', $delivery->status);
        self::assertNull($delivery->published_at);
    }

    public function test_duplicate_only_batch_does_not_advance_versions_or_create_another_event(): void
    {
        $this->persistAccounts(Accounts::data());
        $repo = $this->app->make(JournalRepository::class);
        $id = $repo->post(PostingBatches::batch())->entries[0]->journalEntryId;
        $before = $this->financialSnapshot();
        $result = $repo->post(new PostingBatchData('7', [PostingBatches::entry(), PostingBatches::entry()]));
        self::assertSame(['duplicate', 'duplicate'], array_column($result->entries, 'status'));
        self::assertSame([$id, $id], array_column($result->entries, 'journalEntryId'));
        self::assertEquals($before, $this->financialSnapshot());
    }

    public function test_later_overlapping_batch_only_emits_new_journals_and_their_touched_accounts(): void
    {
        $this->persistAccounts(Accounts::data());
        $repo = $this->app->make(JournalRepository::class);
        $repo->post(PostingBatches::batch());
        $result = $repo->post(new PostingBatchData('7', [PostingBatches::entry(), PostingBatches::entry('Recebido #682', type: 'Receita')]));
        $event = DB::table('outbox_events')->orderByDesc('id')->first();
        $payload = json_decode($event->payload, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['duplicate', 'inserted'], array_column($result->entries, 'status'));
        self::assertSame([$result->entries[1]->journalEntryId], $payload['journal_entry_ids']);
        self::assertSame(['42', '7001'], $payload['account_ids']);
        self::assertSame(2, DB::table('outbox_events')->count());
    }

    public function test_large_cent_values_are_persisted_without_float_conversion(): void
    {
        $this->persistAccounts(Accounts::data());
        $this->app->make(JournalRepository::class)->post(new PostingBatchData('7', [PostingBatches::entry(amount: (string) PHP_INT_MAX)]));
        self::assertSame([PHP_INT_MAX, PHP_INT_MAX], DB::table('ledger_entries')->orderBy('position')->pluck('amount_minor')->all());
    }

    public function test_a_full_internal_batch_has_one_event_and_does_not_use_the_public_page_limit(): void
    {
        $this->persistAccounts(Accounts::data());
        $rows = [];
        for ($i = 0; $i < 500; $i++) {
            $rows[] = new CsvRowData('2026-08-16', 'Serviço '.$i.' #682', '1', 'Receita');
        }
        $response = $this->app->make(PostCsvBatchUseCase::class)->execute(new PostCsvBatchRequest('7', $rows));
        self::assertCount(500, $response->entries);
        self::assertSame(1000, DB::table('ledger_entries')->count());
        self::assertSame(1000, DB::table('financial_states')->where('owner_user_id', 7)->value('revision'));
        self::assertSame(1, DB::table('outbox_events')->count());
        self::assertSame(1, DB::table('outbox_deliveries')->count());
        self::assertSame(5, DB::selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS timeout')->timeout);
    }

    public function test_accounts_are_revalidated_after_preparation_under_the_write_locks(): void
    {
        $this->persistAccounts(Accounts::data());
        $batch = PostingBatches::batch();
        DB::table('accounts')->where('id', 7002)->update(['active' => 0]);
        try {
            $this->app->make(JournalRepository::class)->post($batch);
            self::fail('An account deactivated after preparation must not receive a posting.');
        } catch (DomainViolation $error) {
            self::assertSame('inactive_account', $error->reason);
        }
        $this->assertEmptyLedger();
    }

    #[DataProvider('missingStateRows')]
    public function test_missing_projection_or_financial_state_refuses_the_whole_batch(string $table, string $column, int $id): void
    {
        $this->persistAccounts(Accounts::data());
        DB::table($table)->where($column, $id)->delete();
        try {
            $this->app->make(JournalRepository::class)->post(PostingBatches::batch());
            self::fail('Missing invalidation metadata must fail the operation.');
        } catch (PostingUnavailable) {
            $this->assertEmptyLedger();
        }
    }

    public static function missingStateRows(): iterable
    {
        yield 'financial state' => ['financial_states', 'owner_user_id', 7];
        yield 'financial projection' => ['account_balances', 'account_id', 42];
        yield 'technical projection' => ['account_balances', 'account_id', 7002];
    }

    #[DataProvider('failurePoints')]
    public function test_failures_after_sql_writes_roll_back_entries_versions_and_outbox(string $fragment): void
    {
        $this->persistAccounts(Accounts::data());
        $before = $this->financialSnapshot();
        DB::listen(static function (QueryExecuted $query) use ($fragment): void {
            if (str_starts_with(str_replace(chr(96), '', $query->sql), $fragment)) {
                throw new RuntimeException('Simulated failure after a successful SQL write.');
            }
        });
        try {
            $this->app->make(JournalRepository::class)->post(PostingBatches::batch());
            self::fail('The injected failure must interrupt the transaction.');
        } catch (RuntimeException $error) {
            self::assertSame('Simulated failure after a successful SQL write.', $error->getMessage());
        }
        self::assertEquals($before, $this->financialSnapshot());
    }

    public static function failurePoints(): iterable
    {
        yield 'after header' => ['insert into journal_entries'];
        yield 'after both postings and trigger updates' => ['insert into ledger_entries'];
        yield 'after event' => ['insert into outbox_events'];
        yield 'after pending delivery' => ['insert into outbox_deliveries'];
    }

    public function test_outer_rollback_also_discards_the_repository_savepoint_and_event(): void
    {
        $this->persistAccounts(Accounts::data());
        $before = $this->financialSnapshot();
        DB::beginTransaction();
        $this->app->make(JournalRepository::class)->post(PostingBatches::batch());
        self::assertSame(2, DB::table('ledger_entries')->count());
        DB::rollBack();
        self::assertEquals($before, $this->financialSnapshot());
    }

    public function test_trigger_overflow_is_a_storage_failure_and_not_a_duplicate(): void
    {
        $this->persistAccounts(Accounts::data());
        DB::table('financial_states')->where('owner_user_id', 7)->update(['revision' => '18446744073709551615']);
        $before = $this->financialSnapshot();
        try {
            $this->app->make(JournalRepository::class)->post(PostingBatches::batch());
            self::fail('An overflowing revision cannot be acknowledged as a duplicate.');
        } catch (PostingUnavailable) {
            self::assertEquals($before, $this->financialSnapshot());
        }
    }

    public function test_corrupt_existing_header_cannot_be_reported_as_a_successful_duplicate(): void
    {
        $this->persistAccounts(Accounts::data());
        $entry = PostingBatches::entry();
        DB::table('journal_entries')->insert(array_replace($this->journalData($entry->sourceRowHash), [
            'canonical_record' => $entry->canonicalRecord,
        ]));
        $this->expectException(DomainViolation::class);
        $this->app->make(JournalRepository::class)->post(PostingBatches::batch());
    }

    private function financialSnapshot(): array
    {
        $snapshot = [];
        foreach (['journal_entries', 'ledger_entries', 'account_balances', 'financial_states', 'outbox_events', 'outbox_deliveries'] as $table) {
            $snapshot[$table] = DB::table($table)->get()->all();
        }

        return $snapshot;
    }

    private function assertEmptyLedger(): void
    {
        foreach (['journal_entries', 'ledger_entries', 'outbox_events', 'outbox_deliveries'] as $table) {
            self::assertSame(0, DB::table($table)->count(), $table);
        }
    }
}
