<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Application\Imports\Contracts\CsvChunkReader;
use App\Application\Imports\Contracts\ImportAccountRepository;
use App\Application\Imports\Contracts\ImportChunkRepository;
use App\Application\Imports\Contracts\ImportFileStorage;
use App\Application\Imports\CsvRowCanonicalizer;
use App\Application\Imports\Data\CommitImportChunkData;
use App\Application\Imports\Data\CsvChunkData;
use App\Application\Imports\Data\CsvRecordData;
use App\Application\Imports\Data\CsvRowData;
use App\Application\Imports\Data\ImportAccountsData;
use App\Application\Imports\Data\ImportChunkSourceData;
use App\Application\Imports\Data\ProcessImportChunkRequest;
use App\Application\Imports\Data\ReadCsvChunkData;
use App\Application\Imports\Data\StoredImportFileData;
use App\Application\Imports\Data\ValidatedImportFileData;
use App\Application\Imports\ImportUnavailable;
use App\Application\Imports\InvalidImportFile;
use App\Application\Imports\ProcessImportChunkUseCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Core\Support\Accounts;

final class ProcessImportChunkUseCaseTest extends TestCase
{
    private function source(bool $legacy = false): ImportChunkSourceData
    {
        return new ImportChunkSourceData('9', '1', '7', new StoredImportFileData('imports/'.str_repeat('a', 64).'.csv', 1000, str_repeat('b', 64)), 27, '1', '0', 'lease', $legacy ? null : [str_repeat('c', 64)]);
    }

    public function test_busy_or_obsolete_delivery_does_not_read_or_post(): void
    {
        $repo = $this->createMock(ImportChunkRepository::class);
        $repo->expects(self::once())->method('claim')->with('9')->willReturn(null);
        $reader = $this->createMock(CsvChunkReader::class);
        $reader->expects(self::never())->method('read');
        $useCase = new ProcessImportChunkUseCase($repo, $reader, $this->createStub(ImportFileStorage::class), $this->createStub(ImportAccountRepository::class), new CsvRowCanonicalizer);
        self::assertFalse($useCase->execute(new ProcessImportChunkRequest('9'))->processed);
    }

    public function test_bulk_resolution_preserves_valid_rows_and_isolates_business_rejections(): void
    {
        $source = $this->source();
        $records = [];
        foreach ([['Valid #682', '10'], ['Foreign #683', '10'], ['Inactive #684', '10'], ['Missing #999', '10'], ['Invalid #682', '1e3']] as $i => $input) {
            $records[] = new CsvRecordData((string) ($i + 2), new CsvRowData('2026-08-16', $input[0], $input[1], 'Receita'));
        }
        $records[] = new CsvRecordData('7', null, 'invalid_csv_columns');
        $repo = $this->createMock(ImportChunkRepository::class);
        $repo->expects(self::once())->method('claim')->willReturn($source);
        $repo->expects(self::once())->method('commit')->willReturnCallback(function (CommitImportChunkData $data): void {
            self::assertSame([null, 'account_access_denied', 'inactive_account', 'account_not_found', 'invalid_positive_integer', 'invalid_csv_columns'], array_column($data->rows, 'errorCode'));
            self::assertSame('42', $data->rows[0]->posting->financialAccountId);
            self::assertSame('10', $data->rows[0]->posting->journal->postings[0]->amountMinor);
            self::assertSame(['2', '3', '4', '5', '6', '7'], array_column($data->rows, 'recordNumber'));
        });
        $reader = $this->createStub(CsvChunkReader::class);
        $reader->method('read')->willReturn(new CsvChunkData($records, 1000, '7', true));
        $accounts = $this->createMock(ImportAccountRepository::class);
        $accounts->expects(self::once())->method('resolve')->with('7', ['682', '683', '684', '999'])->willReturn(new ImportAccountsData(Accounts::data()));
        $files = $this->createMock(ImportFileStorage::class);
        $files->expects(self::never())->method('validate');
        self::assertTrue((new ProcessImportChunkUseCase($repo, $reader, $files, $accounts, new CsvRowCanonicalizer))->execute(new ProcessImportChunkRequest('9'))->processed);
    }

    public function test_structural_error_fails_import_without_committing_partial_records(): void
    {
        $source = $this->source();
        $repo = $this->createMock(ImportChunkRepository::class);
        $repo->expects(self::once())->method('claim')->willReturn($source);
        $repo->expects(self::never())->method('commit');
        $repo->expects(self::once())->method('fail')->with($source, 'malformed_csv', false)->willReturn(true);
        $reader = $this->createStub(CsvChunkReader::class);
        $reader->method('read')->willThrowException(new InvalidImportFile('malformed_csv'));
        self::assertFalse((new ProcessImportChunkUseCase($repo, $reader, $this->createStub(ImportFileStorage::class), $this->createStub(ImportAccountRepository::class), new CsvRowCanonicalizer))->execute(new ProcessImportChunkRequest('9'))->processed);
    }

    #[DataProvider('terminal')]
    public function test_technical_failure_releases_lease_and_honors_durable_retry_budget(bool $terminal): void
    {
        $source = $this->source();
        $repo = $this->createMock(ImportChunkRepository::class);
        $repo->expects(self::once())->method('claim')->willReturn($source);
        $repo->expects(self::once())->method('fail')->with($source, 'import_chunk_unavailable', true)->willReturn($terminal);
        $reader = $this->createStub(CsvChunkReader::class);
        $reader->method('read')->willThrowException(new ImportUnavailable);
        if (! $terminal) {
            $this->expectException(ImportUnavailable::class);
        }
        $result = (new ProcessImportChunkUseCase($repo, $reader, $this->createStub(ImportFileStorage::class), $this->createStub(ImportAccountRepository::class), new CsvRowCanonicalizer))->execute(new ProcessImportChunkRequest('9'));
        self::assertFalse($result->processed);
    }

    public static function terminal(): array
    {
        return [[false], [true]];
    }

    public function test_previously_prepared_upload_gets_manifest_and_commits_it_with_checkpoint(): void
    {
        $source = $this->source(true);
        $hashes = [str_repeat('c', 64)];
        $repo = $this->createMock(ImportChunkRepository::class);
        $repo->expects(self::once())->method('claim')->willReturn($source);
        $repo->expects(self::once())->method('commit')->willReturnCallback(fn (CommitImportChunkData $data) => self::assertSame($hashes, $data->blockHashes));
        $files = $this->createMock(ImportFileStorage::class);
        $files->expects(self::once())->method('validate')->with($source->file)->willReturn(new ValidatedImportFileData(27, $hashes));
        $reader = $this->createMock(CsvChunkReader::class);
        $reader->expects(self::once())->method('read')->with(new ReadCsvChunkData($source->file, 27, '1', $hashes))->willReturn(new CsvChunkData([new CsvRecordData('2', null, 'invalid_csv_columns')], 1000, '2', true));
        $accounts = $this->createStub(ImportAccountRepository::class);
        $accounts->method('resolve')->willReturn(new ImportAccountsData([]));
        self::assertTrue((new ProcessImportChunkUseCase($repo, $reader, $files, $accounts, new CsvRowCanonicalizer))->execute(new ProcessImportChunkRequest('9'))->processed);
    }
}
