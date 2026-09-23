<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Integration\Support\UsesCsvImports;

final class ImportChunkConcurrencyTest extends TestCase
{
    use UsesCsvImports { tearDown as private cleanupImport; }

    private array $processes = [];

    protected function tearDown(): void
    {
        foreach ($this->processes as $p) {
            if ($p->isRunning()) {
                $p->stop(0);
            }
        }
        $this->cleanupImport();
    }

    private function worker(string $delivery, string $barrier): Process
    {
        $p = new Process([PHP_BINARY, __DIR__.'/Support/outbox-worker.php'], base_path(), ['IMPORT_UPLOAD_ROOT' => $this->uploadRoot], timeout: 40);
        $p->setInput(json_encode(['chunk_delivery' => $delivery, 'barrier' => $barrier], JSON_THROW_ON_ERROR));
        $p->start();
        $this->processes[] = $p;

        return $p;
    }

    private function concurrently(string $first, string $second): array
    {
        $barrier = $this->uploadRoot.'/release';
        $workers = [$this->worker($first, $barrier), $this->worker($second, $barrier)];
        foreach ($workers as $p) {
            $deadline = microtime(true) + 8;
            while (! str_contains($p->getOutput(), "ready\n") && $p->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            self::assertStringContainsString("ready\n", $p->getOutput(), $p->getErrorOutput());
        }
        touch($barrier);
        $results = [];
        foreach ($workers as $p) {
            $p->wait();
            self::assertSame(0, $p->getExitCode(), $p->getErrorOutput());
            $lines = explode("\n", trim($p->getOutput()));
            $results[] = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR)['processed'];
        }

        return $results;
    }

    public function test_two_workers_for_same_checkpoint_only_commit_once(): void
    {
        $id = $this->uploadCsv($this->csv(80));
        $delivery = $this->nextDelivery($id);
        $results = $this->concurrently($delivery, $delivery);
        sort($results);
        self::assertSame([false, true], $results);
        self::assertSame(80, DB::table('imports')->find($id)->processed_rows);
        self::assertSame(80, DB::table('import_rows')->count());
        self::assertSame(160, DB::table('ledger_entries')->count());
        self::assertSame(1, DB::table('outbox_events')->where('event_type', 'ImportCompleted')->count());
    }

    public function test_concurrent_overlapping_uploads_preserve_union_and_individual_counters(): void
    {
        $a = $this->uploadCsv($this->csv(100));
        $b = $this->uploadCsv($this->csv(100, 51));
        self::assertSame([true, true], $this->concurrently($this->nextDelivery($a), $this->nextDelivery($b)));
        self::assertSame(150, DB::table('journal_entries')->count());
        self::assertSame(300, DB::table('ledger_entries')->count());
        self::assertSame(200, DB::table('import_rows')->count());
        self::assertSame(150, (int) DB::table('imports')->sum('inserted_rows'));
        self::assertSame(50, (int) DB::table('imports')->sum('duplicate_rows'));
        self::assertSame(300, (int) DB::table('financial_states')->where('owner_user_id', '7')->value('revision'));
    }
}
