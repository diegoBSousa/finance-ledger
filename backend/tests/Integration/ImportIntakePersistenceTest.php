<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Imports\Contracts\ImportFileStorage;
use App\Application\Imports\Contracts\ImportPreparationRepository;
use App\Application\Imports\Data\PrepareImportRequest;
use App\Application\Imports\Data\UploadCsvRequest;
use App\Application\Imports\ImportUnavailable;
use App\Application\Imports\PrepareImportUseCase;
use App\Application\Imports\UploadCsvUseCase;
use App\Infrastructure\Storage\LocalImportFileStorage;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Integration\Support\UsesCommittedMysql;
use Tests\Support\TemporaryUploads;

final class ImportIntakePersistenceTest extends TestCase
{
    use TemporaryUploads;
    use UsesCommittedMysql { tearDown as private cleanupDatabase; }

    protected function tearDown(): void
    {
        $this->removeUploadRoot();
        $this->cleanupDatabase();
    }

    private function prepare(): void
    {
        $this->createUploadRoot();
        $this->createUser('7');
        $this->createUser('8');
        $this->commitFixtures();
        $this->app->instance(ImportFileStorage::class, new LocalImportFileStorage($this->uploadRoot));
    }

    private function upload(string $content = "date,description,amount,type\n2026-08-16,test #682,10,Receita\n"): string
    {
        return $this->app->make(UploadCsvUseCase::class)->execute(new UploadCsvRequest('7', $this->sourceFile($content), 'a.csv'))->import->id;
    }

    public function test_registration_commits_file_import_and_event_without_redis_or_financial_writes(): void
    {
        $this->prepare();
        $id = $this->upload();
        self::assertSame('pending', DB::table('imports')->value('status'));
        self::assertSame('ImportRequested', DB::table('outbox_events')->value('event_type'));
        self::assertSame(['import_id' => $id], json_decode(DB::table('outbox_events')->value('payload'), true));
        self::assertSame('pending', DB::table('outbox_deliveries')->value('status'));
        self::assertSame(0, DB::table('journal_entries')->count());
        self::assertSame(0, DB::table('ledger_entries')->count());
    }

    #[DataProvider('writeFailures')]
    public function test_sql_failure_rolls_back_every_registration_write_and_removes_confirmed_orphan(string $table): void
    {
        $this->prepare();
        DB::listen(function (QueryExecuted $q) use ($table): void {
            if (str_starts_with($q->sql, 'insert into `'.$table.'`')) {
                throw new PDOException('Injected intake failure');
            }
        });
        try {
            $this->upload();
            self::fail('Injected failure must propagate.');
        } catch (ImportUnavailable) {
            foreach (['imports', 'outbox_events', 'outbox_deliveries'] as $name) {
                self::assertSame(0, DB::table($name)->count());
            }
            self::assertSame([], glob($this->uploadRoot.'/imports/*'));
        }
    }

    public static function writeFailures(): iterable
    {
        yield ['imports'];
        yield ['outbox_events'];
        yield ['outbox_deliveries'];
    }

    public function test_preparation_is_idempotent_and_atomically_schedules_only_one_first_chunk(): void
    {
        $this->prepare();
        $id = $this->upload();
        $delivery = (string) DB::table('outbox_deliveries')->value('id');
        $useCase = $this->app->make(PrepareImportUseCase::class);
        self::assertTrue($useCase->execute(new PrepareImportRequest($delivery))->prepared);
        self::assertFalse($useCase->execute(new PrepareImportRequest($delivery))->prepared);
        $row = DB::table('imports')->where('id', $id)->first();
        self::assertNotNull($row->prepared_at);
        self::assertSame(strlen("date,description,amount,type\n"), $row->byte_offset);
        self::assertSame(1, $row->last_record_number);
        self::assertSame(0, $row->checkpoint_version);
        self::assertSame(0, $row->processed_rows);
        self::assertSame('pending', $row->status);
        self::assertNull($row->lease_token);
        self::assertSame(2, DB::table('outbox_events')->count());
        self::assertSame(1, DB::table('outbox_deliveries')->where('consumer', 'csv-importer')->count());
        self::assertSame('acknowledged', DB::table('outbox_deliveries')->where('id', $delivery)->value('status'));
        self::assertSame(0, DB::table('ledger_entries')->count());
    }

    public function test_bad_header_fails_the_import_without_scheduling_or_writing_financial_rows(): void
    {
        $this->prepare();
        $id = $this->upload("wrong,header\n");
        $delivery = (string) DB::table('outbox_deliveries')->value('id');
        self::assertFalse($this->app->make(PrepareImportUseCase::class)->execute(new PrepareImportRequest($delivery))->prepared);
        $row = DB::table('imports')->where('id', $id)->first();
        self::assertSame('failed', $row->status);
        self::assertSame('invalid_csv_header', $row->last_error);
        self::assertNotNull($row->finished_at);
        self::assertSame(1, DB::table('outbox_events')->count());
        self::assertSame(0, DB::table('ledger_entries')->count());
    }

    public function test_expired_worker_lease_can_be_reclaimed_and_old_worker_cannot_finish(): void
    {
        $this->prepare();
        $id = $this->upload();
        $delivery = (string) DB::table('outbox_deliveries')->value('id');
        $repo = $this->app->make(ImportPreparationRepository::class);
        $first = $repo->claim($delivery);
        self::assertNotNull($first);
        self::assertNull($repo->claim($delivery));
        DB::table('imports')->where('id', $id)->update(['lease_expires_at' => now()->subMinute()]);
        $second = $repo->claim($delivery);
        self::assertNotSame($first->leaseToken, $second->leaseToken);
        try {
            $repo->complete($delivery, $first, 29);
            self::fail('Expired worker must not finish.');
        } catch (ImportUnavailable) {
            self::assertSame(1, DB::table('outbox_events')->count());
        }
        $repo->release($first);
        self::assertSame($second->leaseToken, DB::table('imports')->where('id', $id)->value('lease_token'));
        $repo->complete($delivery, $second, strlen("date,description,amount,type\n"));
        self::assertSame(2, DB::table('outbox_events')->count());
    }

    public function test_failure_after_creating_next_intent_rolls_back_checkpoint_and_acknowledgment(): void
    {
        $this->prepare();
        $id = $this->upload();
        $delivery = (string) DB::table('outbox_deliveries')->value('id');
        $fail = true;
        DB::listen(function (QueryExecuted $q) use (&$fail): void {
            if ($fail && str_starts_with($q->sql, 'insert into `outbox_deliveries`')) {
                $fail = false;
                throw new PDOException('Injected handoff failure');
            }
        });
        try {
            $this->app->make(PrepareImportUseCase::class)->execute(new PrepareImportRequest($delivery));
            self::fail('Handoff must fail.');
        } catch (ImportUnavailable) {
            self::assertSame(1, DB::table('outbox_events')->count());
            self::assertSame('pending', DB::table('outbox_deliveries')->value('status'));
            self::assertNull(DB::table('imports')->where('id', $id)->value('prepared_at'));
            self::assertNull(DB::table('imports')->where('id', $id)->value('lease_token'));
        }
        self::assertTrue($this->app->make(PrepareImportUseCase::class)->execute(new PrepareImportRequest($delivery))->prepared);
    }
}
