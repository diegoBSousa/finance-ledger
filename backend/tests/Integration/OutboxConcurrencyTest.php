<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Imports\Contracts\ImportFileStorage;
use App\Application\Imports\Contracts\ImportRepository;
use App\Application\Imports\Data\UploadCsvRequest;
use App\Application\Imports\UploadCsvUseCase;
use App\Application\Outbox\Contracts\OutboxRepository;
use App\Infrastructure\Storage\LocalImportFileStorage;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Core\Contracts\ImportRepositoryContract;
use Tests\Integration\Support\UsesCommittedMysql;
use Tests\Support\TemporaryUploads;

final class OutboxConcurrencyTest extends TestCase
{
    use TemporaryUploads;
    use UsesCommittedMysql { tearDown as private cleanupDatabase; }

    /** @var list<Process> */
    private array $processes = [];

    protected function tearDown(): void
    {
        foreach ($this->processes as $p) {
            if ($p->isRunning()) {
                $p->stop(0);
            }
        }$this->removeUploadRoot();
        $this->cleanupDatabase();
    }

    private function prepare(): void
    {
        $this->createUploadRoot();
        $this->createUser();
        $this->commitFixtures();
    }

    private function worker(array $input): Process
    {
        $p = new Process([PHP_BINARY, __DIR__.'/Support/outbox-worker.php'], base_path(), ['IMPORT_UPLOAD_ROOT' => $this->uploadRoot], timeout: 20);
        $p->setInput(json_encode($input, JSON_THROW_ON_ERROR));
        $p->start();
        $this->processes[] = $p;

        return $p;
    }

    private function ready(Process $p): void
    {
        $deadline = microtime(true) + 5;
        while (! str_contains($p->getOutput(), "ready\n") && $p->isRunning() && microtime(true) < $deadline) {
            usleep(10000);
        }
        self::assertStringContainsString("ready\n", $p->getOutput(), $p->getErrorOutput());
    }

    private function workerResult(Process $p): array
    {
        $p->wait();
        self::assertSame(0, $p->getExitCode(), $p->getErrorOutput());
        $lines = explode("\n", trim($p->getOutput()));

        return json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_two_relays_claim_disjoint_deliveries_and_skip_a_locked_row(): void
    {
        $this->prepare();
        for ($i = 0; $i < 12; $i++) {
            $this->app->make(ImportRepository::class)->register(ImportRepositoryContract::registration());
        }
        DB::beginTransaction();
        $locked = DB::table('outbox_deliveries')->orderBy('id')->lockForUpdate()->first();
        $barrier = $this->uploadRoot.'/release';
        $a = $this->worker(['barrier' => $barrier]);
        $b = $this->worker(['barrier' => $barrier]);
        $this->ready($a);
        $this->ready($b);
        touch($barrier);
        $first = array_column($this->workerResult($a)['deliveries'], 'id');
        $second = array_column($this->workerResult($b)['deliveries'], 'id');
        self::assertSame([], array_intersect($first, $second));
        self::assertCount(11, [...$first, ...$second]);
        self::assertNotContains((string) $locked->id, [...$first, ...$second]);
        DB::rollBack();
        $remaining = $this->app->make(OutboxRepository::class)->claim()->deliveries;
        self::assertCount(1, $remaining);
        self::assertSame((string) $locked->id, $remaining[0]->id);
    }

    public function test_two_consumers_prepare_one_import_and_create_one_next_intent(): void
    {
        $this->prepare();
        $this->app->instance(ImportFileStorage::class, new LocalImportFileStorage($this->uploadRoot));
        $this->app->make(UploadCsvUseCase::class)->execute(new UploadCsvRequest('7', $this->sourceFile(), 'a.csv'));
        $delivery = (string) DB::table('outbox_deliveries')->value('id');
        $barrier = $this->uploadRoot.'/release';
        $a = $this->worker(['barrier' => $barrier, 'delivery' => $delivery]);
        $b = $this->worker(['barrier' => $barrier, 'delivery' => $delivery]);
        $this->ready($a);
        $this->ready($b);
        touch($barrier);
        $outcomes = [$this->workerResult($a)['prepared'], $this->workerResult($b)['prepared']];
        sort($outcomes);
        self::assertSame([false, true], $outcomes);
        self::assertSame(1, DB::table('outbox_events')->where('event_type', 'ImportChunkRequested')->count());
        self::assertSame('acknowledged', DB::table('outbox_deliveries')->where('id', $delivery)->value('status'));
        self::assertSame(0, DB::table('ledger_entries')->count());
    }
}
