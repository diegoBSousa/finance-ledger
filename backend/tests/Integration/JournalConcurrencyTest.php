<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Accounting\Contracts\JournalRepository;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Core\Support\Accounts;
use Tests\Core\Support\PostingBatches;
use Tests\Integration\Support\MysqlDatabase;
use Tests\Integration\Support\UsesMysql;

final class JournalConcurrencyTest extends TestCase
{
    use UsesMysql {
        tearDown as private rollbackAndDisconnect;
    }

    /** @var list<Process> */
    private array $processes = [];

    private bool $committedFixtures = false;

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
        $this->rollbackAndDisconnect();
        if ($this->committedFixtures) {
            // Triggers deliberately forbid deleting journals. Reset only the guarded disposable test database.
            MysqlDatabase::migrate($this);
        }
    }

    private function committedAccountsAndWriterLock(): void
    {
        $this->persistAccounts(Accounts::data());
        DB::commit();
        $this->committedFixtures = true;
        DB::beginTransaction();
        DB::table('financial_states')->where('owner_user_id', 7)->lockForUpdate()->first();
    }

    /** @param list<string> $descriptions */
    private function startWorker(array $descriptions): Process
    {
        $process = new Process([PHP_BINARY, __DIR__.'/Support/post-batch-worker.php'], base_path(), timeout: 15);
        $process->setInput(json_encode($descriptions, JSON_THROW_ON_ERROR));
        $process->start();
        $this->processes[] = $process;

        return $process;
    }

    private function awaitReady(Process $process): void
    {
        $deadline = microtime(true) + 5;
        while (! str_contains($process->getOutput(), "ready\n") && $process->isRunning() && microtime(true) < $deadline) {
            usleep(10000);
        }
        self::assertStringContainsString("ready\n", $process->getOutput(), $process->getErrorOutput());
        self::assertTrue($process->isRunning(), 'The writer must wait while the owner lock is held.');
    }

    private function workerResult(Process $process): array
    {
        $process->wait();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $lines = explode("\n", trim($process->getOutput()));

        return json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_concurrent_identical_batches_commit_one_operation_and_return_the_same_id(): void
    {
        $this->committedAccountsAndWriterLock();
        $first = $this->startWorker(['Serviços de Limpeza #682']);
        $second = $this->startWorker(['Serviços de Limpeza #682']);
        $this->awaitReady($first);
        $this->awaitReady($second);
        self::assertSame(0, DB::table('journal_entries')->count());
        DB::commit();
        $a = $this->workerResult($first)[0];
        $b = $this->workerResult($second)[0];
        $statuses = [$a['status'], $b['status']];
        sort($statuses);
        self::assertSame(['duplicate', 'inserted'], $statuses);
        self::assertSame($a['journalEntryId'], $b['journalEntryId']);
        $this->assertCommittedCounts(1, 1);
    }

    public function test_overlapping_reordered_concurrent_batches_commit_the_union_without_double_invalidation(): void
    {
        $this->committedAccountsAndWriterLock();
        $first = $this->startWorker(['A #682', 'B #682']);
        $second = $this->startWorker(['B #682', 'C #682']);
        $this->awaitReady($first);
        $this->awaitReady($second);
        DB::commit();
        $a = $this->workerResult($first);
        $b = $this->workerResult($second);
        self::assertSame($a[1]['journalEntryId'], $b[0]['journalEntryId']);
        $statuses = array_column([...$a, ...$b], 'status');
        self::assertSame(3, count(array_filter($statuses, fn (string $status) => $status === 'inserted')));
        self::assertSame(1, count(array_filter($statuses, fn (string $status) => $status === 'duplicate')));
        $this->assertCommittedCounts(3, 2);
    }

    public function test_waiting_writer_can_insert_after_the_first_writer_rolls_back(): void
    {
        $this->committedAccountsAndWriterLock();
        $this->app->make(JournalRepository::class)->post(PostingBatches::batch());
        $worker = $this->startWorker(['Serviços de Limpeza #682']);
        $this->awaitReady($worker);
        DB::rollBack();
        self::assertSame('inserted', $this->workerResult($worker)[0]['status']);
        $this->assertCommittedCounts(1, 1);
    }

    private function assertCommittedCounts(int $journals, int $events): void
    {
        DB::purge(); // Observe committed data using a fresh connection, not the fixture transaction.
        self::assertSame($journals, DB::table('journal_entries')->count());
        self::assertSame($journals * 2, DB::table('ledger_entries')->count());
        self::assertSame($journals * 2, DB::table('financial_states')->where('owner_user_id', 7)->value('revision'));
        self::assertSame($journals, DB::table('account_balances')->where('account_id', 42)->value('ledger_version'));
        self::assertSame($events, DB::table('outbox_events')->count());
        self::assertSame($events, DB::table('outbox_deliveries')->count());
    }
}
