<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Accounting\Contracts\JournalRepository;
use App\Application\Accounting\Data\PostingBatchData;
use App\Application\Balances\Contracts\BalanceRepository;
use App\Application\Balances\Data\ProjectBalancesRequest;
use App\Application\Balances\Data\RefreshBalanceData;
use App\Application\Balances\ProjectBalancesUseCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Core\Support\Accounts;
use Tests\Core\Support\PostingBatches;
use Tests\Integration\Support\UsesCommittedMysql;

final class BalanceConcurrencyTest extends TestCase
{
    use UsesCommittedMysql {
        tearDown as private cleanupDatabase;
    }

    /** @var list<Process> */
    private array $processes = [];

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
        foreach ($this->files as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        $this->cleanupDatabase();
    }

    private function prepare(): void
    {
        $this->persistAccounts(Accounts::data());
        $this->app->make(JournalRepository::class)->post(PostingBatches::batch());
        $this->commitFixtures();
    }

    private function worker(array $options = [], string $script = 'refresh-balance-worker.php'): Process
    {
        $process = new Process([PHP_BINARY, __DIR__.'/Support/'.$script], base_path(), timeout: 20);
        $process->setInput(json_encode($options, JSON_THROW_ON_ERROR));
        $process->start();
        $this->processes[] = $process;

        return $process;
    }

    private function awaitMarker(Process $process, string $marker = 'ready'): void
    {
        $deadline = microtime(true) + 5;
        while (! str_contains($process->getOutput(), $marker."\n") && $process->isRunning() && microtime(true) < $deadline) {
            usleep(10000);
        }
        self::assertStringContainsString($marker."\n", $process->getOutput(), $process->getErrorOutput());
        self::assertTrue($process->isRunning(), 'The barrier/lock must still hold the process.');
    }

    private function workerResult(Process $process): array
    {
        $process->wait();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $lines = explode("\n", trim($process->getOutput()));

        return json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
    }

    #[DataProvider('writerOutcomes')]
    public function test_refresh_waits_for_writer_and_observes_only_committed_postings(bool $commit, string $balance, string $version): void
    {
        $this->prepare();
        DB::beginTransaction();
        $this->app->make(JournalRepository::class)->post(new PostingBatchData('7', [PostingBatches::entry('Later #682', '100', 'Receita')]));
        $worker = $this->worker();
        $this->awaitMarker($worker);
        $commit ? DB::commit() : DB::rollBack();
        $result = $this->workerResult($worker);
        self::assertSame('ok', $result['status']);
        self::assertSame($balance, $result['result']['balance']['balanceMinor']);
        self::assertSame($version, $result['result']['balance']['calculatedVersion']);
        self::assertSame(0, DB::table('account_balances')->where('account_id', 42)->value('staled'));
    }

    public static function writerOutcomes(): iterable
    {
        yield 'commit' => [true, '-494518', '2'];
        yield 'rollback' => [false, '-494618', '1'];
    }

    public function test_a_writer_after_refresh_marks_stale_again_instead_of_losing_invalidation(): void
    {
        $this->prepare();
        $file = sys_get_temp_dir().'/ledger-release-'.bin2hex(random_bytes(12));
        $this->files[] = $file;
        $refresh = $this->worker(['release_file' => $file]);
        $this->awaitMarker($refresh, 'locked');
        $writer = $this->worker(['After refresh #682'], 'post-batch-worker.php');
        $this->awaitMarker($writer);
        touch($file);
        $result = $this->workerResult($refresh);
        self::assertSame('-494618', $result['result']['balance']['balanceMinor']);
        self::assertSame('inserted', $this->workerResult($writer)[0]['status']);
        $projection = DB::table('account_balances')->where('account_id', 42)->first();
        self::assertSame(1, $projection->staled);
        self::assertSame(2, $projection->ledger_version);
        self::assertSame(1, $projection->calculated_version);
        self::assertSame(-494618, $projection->balance_minor);
        self::assertNotNull($projection->staled_since);
        self::assertSame('-989236', $this->app->make(BalanceRepository::class)->refresh(new RefreshBalanceData('7', '42'))->balance->balanceMinor);
    }

    public function test_two_refreshers_reuse_one_calculation_after_rechecking_under_lock(): void
    {
        $this->prepare();
        DB::beginTransaction();
        DB::table('account_balances')->where('account_id', 42)->lockForUpdate()->first();
        $first = $this->worker();
        $second = $this->worker();
        $this->awaitMarker($first);
        $this->awaitMarker($second);
        DB::commit();
        $a = $this->workerResult($first)['result'];
        $b = $this->workerResult($second)['result'];
        self::assertEquals($a['balance'], $b['balance']);
        $recalculated = [$a['recalculated'], $b['recalculated']];
        sort($recalculated);
        self::assertSame([false, true], $recalculated);
    }

    public function test_background_skips_a_locked_account_but_user_refresh_exhausts_bounded_retries(): void
    {
        $this->prepare();
        DB::beginTransaction();
        DB::table('account_balances')->where('account_id', 42)->lockForUpdate()->first();
        $projected = $this->app->make(ProjectBalancesUseCase::class)->execute(new ProjectBalancesRequest);
        self::assertSame(1, $projected->skipped);
        self::assertSame(1, $projected->recalculated);
        $user = $this->worker(['timeout' => true]);
        $this->awaitMarker($user);
        $result = $this->workerResult($user);
        self::assertSame(['status' => 'unavailable', 'attempts' => 3], $result);
        self::assertSame(1, DB::table('account_balances')->where('account_id', 42)->value('staled'));
        DB::rollBack();
        $retry = $this->app->make(ProjectBalancesUseCase::class)->execute(new ProjectBalancesRequest);
        self::assertSame(1, $retry->recalculated);
    }
}
