<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Imports\Contracts\ImportChunkRepository;
use App\Application\Imports\ImportUnavailable;
use App\Domain\Accounting\AccountKind;
use App\Infrastructure\Persistence\AccountProvisioner;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Integration\Support\UsesCsvImports;

final class ImportChunkPersistenceTest extends TestCase
{
    use UsesCsvImports;

    public function test_chunk_commits_exactly_500_then_resumes_and_duplicate_delivery_is_harmless(): void
    {
        $id = $this->uploadCsv($this->csv(501));
        $first = $this->nextDelivery($id);
        self::assertTrue($this->processDelivery($first));
        $row = DB::table('imports')->find($id);
        self::assertSame('processing', $row->status);
        self::assertSame(500, $row->processed_rows);
        self::assertSame(501, $row->last_record_number);
        self::assertSame(1, $row->checkpoint_version);
        self::assertSame(1000, DB::table('ledger_entries')->count());
        self::assertFalse($this->processDelivery($first));
        $this->consumeImport($id);
        $row = DB::table('imports')->find($id);
        self::assertSame('completed', $row->status);
        self::assertSame(501, $row->inserted_rows);
        self::assertSame($row->file_size_bytes, $row->byte_offset);
        self::assertSame(502, $row->last_record_number);
        self::assertSame(501, DB::table('import_rows')->count());
        self::assertSame(1002, DB::table('ledger_entries')->count());
        self::assertSame(1, DB::table('outbox_events')->where('event_type', 'ImportCompleted')->count());
        self::assertTrue((bool) DB::table('account_balances')->where('account_id', 42)->value('staled'));
    }

    public function test_partial_overlapping_reordered_uploads_post_only_the_union(): void
    {
        $a = $this->uploadCsv($this->csv(501));
        $this->processDelivery($this->nextDelivery($a));
        $lines = explode("\n", trim($this->csv(103, 400)));
        $header = array_shift($lines);
        $b = $this->uploadCsv($header."\n".implode("\n", array_reverse($lines))."\n".$lines[0]."\n");
        $this->consumeImport($b);
        $this->consumeImport($a);
        self::assertSame(502, DB::table('journal_entries')->count());
        self::assertSame(1004, DB::table('ledger_entries')->count());
        $result = DB::table('imports')->find($b);
        self::assertSame(2, $result->inserted_rows);
        self::assertSame(102, $result->duplicate_rows);
        self::assertSame(1, DB::table('imports')->find($a)->duplicate_rows);
    }

    public function test_business_errors_are_isolated_and_counter_totals_remain_exact(): void
    {
        $id = $this->uploadCsv("date,description,amount,type\n2026-08-16,Valid #682,10,Receita\n2026-08-16,Foreign #683,10,Receita\n2026-08-16,Missing #999,10,Receita\n2026-08-16,Inactive #684,10,Receita\n2026-08-16,Invalid #682,0,Receita\nwrong,columns\n");
        $this->consumeImport($id);
        $row = DB::table('imports')->find($id);
        self::assertSame('completed_with_errors', $row->status);
        self::assertSame([6, 1, 0, 5], [$row->processed_rows, $row->inserted_rows, $row->duplicate_rows, $row->rejected_rows]);
        self::assertSame(2, DB::table('ledger_entries')->count());
        self::assertSame(['account_access_denied', 'account_not_found', 'inactive_account', 'invalid_positive_integer', 'invalid_csv_columns'], DB::table('import_rows')->where('status', 'rejected')->orderBy('source_record_number')->pluck('error_code')->all());
    }

    #[DataProvider('failurePoints')]
    public function test_failure_rolls_back_financial_rows_checkpoint_and_next_intent(string $target): void
    {
        $id = $this->uploadCsv($this->csv(501));
        $delivery = $this->nextDelivery($id);
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$armed, $target): void {
            if ($armed && str_starts_with($query->sql, $target)
                && ($target !== 'insert into `outbox_deliveries`' || in_array('csv-importer', $query->bindings, true))) {
                $armed = false;
                throw new PDOException('Injected chunk failure.');
            }
        });
        try {
            $this->processDelivery($delivery);
            self::fail('Injected failure was hidden.');
        } catch (ImportUnavailable) {
        }
        self::assertFalse($armed, 'Failure injection did not run.');
        self::assertSame(0, DB::table('journal_entries')->count());
        self::assertSame(0, DB::table('ledger_entries')->count());
        self::assertSame(0, DB::table('import_rows')->count());
        self::assertSame(0, DB::table('imports')->find($id)->checkpoint_version);
        self::assertSame(0, DB::table('imports')->find($id)->processed_rows);
        self::assertSame(1, DB::table('outbox_events')->where('event_type', 'ImportChunkRequested')->count());
        self::assertSame(0, (int) DB::table('financial_states')->where('owner_user_id', '7')->value('revision'));
        self::assertSame('pending', DB::table('outbox_deliveries')->where('id', $delivery)->value('status'));
        self::assertTrue($this->processDelivery($delivery));
        self::assertSame(500, DB::table('imports')->find($id)->processed_rows);
    }

    public static function failurePoints(): array
    {
        return [['insert into `ledger_entries`'], ['insert into `import_rows`'], ['update `imports` set `processed_rows`'], ['insert into `outbox_deliveries`'], ['update `outbox_deliveries`']];
    }

    public function test_structural_failure_preserves_previous_committed_chunk(): void
    {
        $id = $this->uploadCsv($this->csv(500).'2026-08-16,"unterminated');
        $this->consumeImport($id);
        $row = DB::table('imports')->find($id);
        self::assertSame('failed', $row->status);
        self::assertSame('malformed_csv', $row->last_error);
        self::assertSame(500, $row->processed_rows);
        self::assertSame(1000, DB::table('ledger_entries')->count());
        self::assertSame(1, DB::table('outbox_events')->where('event_type', 'ImportFailed')->count());
    }

    public function test_prepared_file_from_previous_release_is_upgraded_on_first_chunk(): void
    {
        $id = $this->uploadCsv($this->csv(1));
        DB::table('imports')->where('id', $id)->update(['file_block_hashes' => null]);
        $this->consumeImport($id);
        self::assertNotNull(DB::table('imports')->find($id)->file_block_hashes);
        self::assertSame('completed', DB::table('imports')->find($id)->status);
    }

    public function test_expired_lease_cannot_release_a_new_owner_and_attempt_budget_survives_republication(): void
    {
        $id = $this->uploadCsv($this->csv(1));
        $delivery = $this->nextDelivery($id);
        $repo = $this->app->make(ImportChunkRepository::class);
        $old = $repo->claim($delivery);
        self::assertNull($repo->claim($delivery));
        DB::table('imports')->where('id', $id)->update(['lease_expires_at' => now()->subMinute()]);
        $new = $repo->claim($delivery);
        self::assertNotSame($old->leaseToken, $new->leaseToken);
        self::assertFalse($repo->fail($old, 'stale_worker', false));
        self::assertSame($new->leaseToken, DB::table('imports')->find($id)->lease_token);
        self::assertFalse($repo->fail($new, 'import_chunk_unavailable', true));
        for ($i = 3; $i <= 5; $i++) {
            $source = $repo->claim($delivery);
            self::assertSame($i === 5, $repo->fail($source, 'import_chunk_unavailable', true));
        }
        self::assertNull($repo->claim($delivery));
        self::assertSame('failed', DB::table('imports')->find($id)->status);
        self::assertSame('import_chunk_attempts_exhausted', DB::table('imports')->find($id)->last_error);
        self::assertSame(0, DB::table('ledger_entries')->count());
    }

    public function test_same_size_tampering_after_a_checkpoint_is_detected(): void
    {
        $id = $this->uploadCsv($this->csv(501));
        $this->processDelivery($this->nextDelivery($id));
        $row = DB::table('imports')->find($id);
        $path = $this->uploadRoot.'/'.$row->file_path;
        file_put_contents($path, str_replace('Row 501', 'Row 999', file_get_contents($path)));
        $this->consumeImport($id);
        self::assertSame('source_file_changed', DB::table('imports')->find($id)->last_error);
        self::assertSame(500, DB::table('journal_entries')->count());
    }

    public function test_account_change_after_bulk_resolution_rolls_back_then_is_rejected_on_retry(): void
    {
        $id = $this->uploadCsv($this->csv(1));
        $delivery = $this->nextDelivery($id);
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$armed): void {
            if ($armed && DB::transactionLevel() === 0 && str_contains($query->sql, 'from `accounts`')) {
                $armed = false;
                DB::table('accounts')->where('id', '42')->update(['active' => false]);
            }
        });
        try {
            $this->processDelivery($delivery);
            self::fail('Stale account data was accepted.');
        } catch (ImportUnavailable) {
        }
        self::assertFalse($armed);
        self::assertSame(0, DB::table('journal_entries')->count());
        self::assertSame(0, DB::table('import_rows')->count());
        self::assertTrue($this->processDelivery($delivery));
        self::assertSame('completed_with_errors', DB::table('imports')->find($id)->status);
        self::assertSame('inactive_account', DB::table('import_rows')->value('error_code'));
    }

    public function test_lost_commit_reply_does_not_repeat_committed_financial_effects(): void
    {
        $id = $this->uploadCsv($this->csv(1));
        $delivery = $this->nextDelivery($id);
        $armed = true;
        $this->app['events']->listen(TransactionCommitted::class, function () use (&$armed, $id): void {
            if ($armed && DB::transactionLevel() === 0 && (int) DB::table('imports')->where('id', $id)->value('checkpoint_version') === 1) {
                $armed = false;
                throw new PDOException('Lost commit response.');
            }
        });
        try {
            $this->processDelivery($delivery);
            self::fail('Commit response loss not surfaced.');
        } catch (ImportUnavailable) {
        }
        self::assertFalse($armed);
        self::assertSame(1, DB::table('imports')->find($id)->processed_rows);
        self::assertSame(2, DB::table('ledger_entries')->count());
        self::assertFalse($this->processDelivery($delivery));
        self::assertSame(2, DB::table('ledger_entries')->count());
        self::assertSame(1, DB::table('import_rows')->count());
    }

    public function test_reupload_can_import_a_previously_rejected_account_after_regularization(): void
    {
        $content = "date,description,amount,type\n2026-08-16,Missing #999,10,Receita\n";
        $first = $this->uploadCsv($content);
        $this->consumeImport($first);
        self::assertSame(1, DB::table('imports')->find($first)->rejected_rows);
        $this->app->make(AccountProvisioner::class)->create('7', AccountKind::Asset, '999');
        $second = $this->uploadCsv($content);
        $this->consumeImport($second);
        self::assertSame(1, DB::table('imports')->find($second)->inserted_rows);
        self::assertSame(2, DB::table('ledger_entries')->count());
    }
}
