<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Application\Imports\Contracts\ImportFileStorage;
use App\Application\Imports\Contracts\ImportRepository;
use App\Application\Imports\Data\GetImportRequest;
use App\Application\Imports\Data\UploadCsvRequest;
use App\Application\Imports\GetImportUseCase;
use App\Application\Imports\ImportNotFound;
use App\Application\Imports\ImportUnavailable;
use App\Application\Imports\InvalidImportFile;
use App\Application\Imports\UploadCsvUseCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Core\Contracts\ImportRepositoryContract;
use Tests\Doubles\InMemoryImportRepository;

final class ImportIntakeUseCasesTest extends TestCase
{
    public function test_upload_stores_private_source_and_registers_owned_pending_import(): void
    {
        $files = $this->createMock(ImportFileStorage::class);
        $files->expects(self::once())->method('store')->with('/trusted/upload')->willReturn(ImportRepositoryContract::registration()->file);
        $repo = new InMemoryImportRepository;
        $result = (new UploadCsvUseCase($files, $repo))->execute(new UploadCsvRequest('7', '/trusted/upload', '../transactions.CSV'));
        self::assertSame('transactions.CSV', $result->import->originalName);
        self::assertSame('pending', $result->import->status);
    }

    #[DataProvider('unsafeCleanup')]
    public function test_failure_cleanup_is_conservative_about_uncertain_commits(?bool $referenced): void
    {
        $files = $this->createMock(ImportFileStorage::class);
        $files->method('store')->willReturn(ImportRepositoryContract::registration()->file);
        $files->expects($referenced === false ? self::once() : self::never())->method('delete');
        $repo = $this->createStub(ImportRepository::class);
        $repo->method('register')->willThrowException(new ImportUnavailable);
        if ($referenced === null) {
            $repo->method('referencesFile')->willThrowException(new ImportUnavailable);
        } else {
            $repo->method('referencesFile')->willReturn($referenced);
        }
        $this->expectException(ImportUnavailable::class);
        (new UploadCsvUseCase($files, $repo))->execute(new UploadCsvRequest('7', '/trusted/upload', 'a.csv'));
    }

    public static function unsafeCleanup(): iterable
    {
        yield [false];
        yield [true];
        yield [null];
    }

    public function test_non_csv_name_is_rejected_before_storage(): void
    {
        $files = $this->createMock(ImportFileStorage::class);
        $files->expects(self::never())->method('store');
        $this->expectException(InvalidImportFile::class);
        (new UploadCsvUseCase($files, new InMemoryImportRepository))->execute(new UploadCsvRequest('7', '/trusted/upload', 'file.zip'));
    }

    public function test_get_hides_import_belonging_to_someone_else(): void
    {
        $repo = new InMemoryImportRepository;
        $row = $repo->register(ImportRepositoryContract::registration());
        $this->expectException(ImportNotFound::class);
        (new GetImportUseCase($repo))->execute(new GetImportRequest('8', $row->id));
    }
}
