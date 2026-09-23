<?php

declare(strict_types=1);

namespace Tests\Integration\Support;

use App\Application\Imports\Contracts\ImportFileStorage;
use App\Application\Imports\Data\PrepareImportRequest;
use App\Application\Imports\Data\ProcessImportChunkRequest;
use App\Application\Imports\Data\UploadCsvRequest;
use App\Application\Imports\PrepareImportUseCase;
use App\Application\Imports\ProcessImportChunkUseCase;
use App\Application\Imports\UploadCsvUseCase;
use App\Infrastructure\Storage\LocalImportFileStorage;
use Illuminate\Support\Facades\DB;
use Tests\Core\Support\Accounts;
use Tests\Support\TemporaryUploads;

trait UsesCsvImports
{
    use TemporaryUploads;
    use UsesCommittedMysql { setUp as private setupDatabase;
        tearDown as private cleanupDatabase; }

    protected function setUp(): void
    {
        $this->setupDatabase();
        $this->createUploadRoot();
        $this->persistAccounts($this->importAccounts());
        $this->commitFixtures();
        $storage = new LocalImportFileStorage($this->uploadRoot);
        $this->app->instance(ImportFileStorage::class, $storage);
        $this->app->instance(LocalImportFileStorage::class, $storage);
    }

    protected function tearDown(): void
    {
        $this->removeUploadRoot();
        $this->cleanupDatabase();
    }

    protected function importAccounts(): array
    {
        return Accounts::data();
    }

    private function csv(int $count, int $start = 1): string
    {
        $data = "date,description,amount,type\n";
        for ($i = $start; $i < $start + $count; $i++) {
            $data .= "2026-08-16,Row {$i} #682,10,Receita\n";
        }

        return $data;
    }

    private function uploadCsv(string $content): string
    {
        $id = $this->app->make(UploadCsvUseCase::class)->execute(new UploadCsvRequest('7', $this->sourceFile($content), 'fixture.csv'))->import->id;
        $delivery = (string) DB::table('outbox_deliveries')->join('outbox_events', 'event_id', '=', 'outbox_events.id')
            ->where('consumer', 'import-preparer')->where('outbox_events.payload->import_id', $id)->value('outbox_deliveries.id');
        self::assertTrue($this->app->make(PrepareImportUseCase::class)->execute(new PrepareImportRequest($delivery))->prepared);

        return $id;
    }

    private function nextDelivery(string $id): ?string
    {
        $delivery = DB::table('outbox_deliveries')->join('outbox_events', 'event_id', '=', 'outbox_events.id')
            ->where('consumer', 'csv-importer')->whereNotIn('outbox_deliveries.status', ['acknowledged', 'failed'])
            ->where('outbox_events.payload->import_id', $id)->orderBy('outbox_deliveries.id')->value('outbox_deliveries.id');

        return $delivery === null ? null : (string) $delivery;
    }

    private function processDelivery(string $delivery): bool
    {
        return $this->app->make(ProcessImportChunkUseCase::class)->execute(new ProcessImportChunkRequest($delivery))->processed;
    }

    private function consumeImport(string $id): void
    {
        $passes = 0;
        while (($delivery = $this->nextDelivery($id)) !== null) {
            self::assertLessThan(10000, ++$passes, 'Import did not terminate.');
            $this->processDelivery($delivery);
        }
    }
}
