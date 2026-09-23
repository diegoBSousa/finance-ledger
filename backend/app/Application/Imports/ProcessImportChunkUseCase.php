<?php

declare(strict_types=1);

namespace App\Application\Imports;

use App\Application\Accounting\Data\PostJournalData;
use App\Application\Accounting\Data\PrepareCsvPostingRequest;
use App\Application\Accounting\PostingUnavailable;
use App\Application\Accounting\PrepareCsvPostingUseCase;
use App\Application\Imports\Contracts\CsvChunkReader;
use App\Application\Imports\Contracts\ImportAccountRepository;
use App\Application\Imports\Contracts\ImportChunkRepository;
use App\Application\Imports\Contracts\ImportFileStorage;
use App\Application\Imports\Data\CommitImportChunkData;
use App\Application\Imports\Data\CsvChunkData;
use App\Application\Imports\Data\PreparedImportRowData;
use App\Application\Imports\Data\ProcessImportChunkRequest;
use App\Application\Imports\Data\ProcessImportChunkResponse;
use App\Application\Imports\Data\ReadCsvChunkData;
use App\Domain\Shared\DomainViolation;

final readonly class ProcessImportChunkUseCase
{
    public function __construct(private ImportChunkRepository $imports, private CsvChunkReader $reader,
        private ImportFileStorage $files, private ImportAccountRepository $accounts, private CsvRowCanonicalizer $canonicalizer) {}

    public function execute(ProcessImportChunkRequest $request): ProcessImportChunkResponse
    {
        $source = $this->imports->claim($request->deliveryId);
        if ($source === null) {
            return new ProcessImportChunkResponse(false);
        }
        try {
            // Prepared files from the preceding release receive their integrity manifest once.
            $hashes = $source->blockHashes ?? $this->files->validate($source->file)->blockHashes;
            $chunk = $this->reader->read(new ReadCsvChunkData($source->file, $source->byteOffset, $source->lastRecordNumber, $hashes));
            $rows = $this->prepare($source->actorUserId, $chunk);
            $this->imports->commit(new CommitImportChunkData($source, $chunk, $rows, $hashes));

            return new ProcessImportChunkResponse(true);
        } catch (InvalidImportFile $error) {
            $this->imports->fail($source, $error->reason, false);

            return new ProcessImportChunkResponse(false);
        } catch (ImportUnavailable|PostingUnavailable|DomainViolation $error) {
            if ($this->imports->fail($source, 'import_chunk_unavailable', true)) {
                return new ProcessImportChunkResponse(false);
            }
            throw new ImportUnavailable;
        }
    }

    /** @return list<PreparedImportRowData> */
    private function prepare(string $actor, CsvChunkData $chunk): array
    {
        $numbers = $errors = [];
        foreach ($chunk->records as $record) {
            if ($record->row === null) {
                $errors[$record->number] = $record->errorCode ?? 'invalid_csv_columns';

                continue;
            }
            try {
                if (strlen($record->row->description) > ChunkLimits::DESCRIPTION_BYTES) {
                    throw new DomainViolation('description_too_large', 'CSV descriptions must not exceed 16384 bytes.');
                }
                $numbers[] = $this->canonicalizer->canonicalize($record->row)->accountNumber;
            } catch (DomainViolation $error) {
                $errors[$record->number] = $error->reason;
            }
        }
        $accounts = $this->accounts->resolve($actor, array_values(array_unique($numbers)));
        $prepare = new PrepareCsvPostingUseCase(new ResolvedImportAccounts($accounts), $this->canonicalizer);
        $rows = [];
        foreach ($chunk->records as $record) {
            if (isset($errors[$record->number]) || $record->row === null) {
                $rows[] = new PreparedImportRowData($record->number, null, $errors[$record->number] ?? 'invalid_csv_columns');

                continue;
            }
            try {
                $rows[] = new PreparedImportRowData($record->number,
                    PostJournalData::fromPrepared($prepare->execute(new PrepareCsvPostingRequest($actor, $record->row))));
            } catch (DomainViolation $error) {
                if ($error->reason === 'repository_contract_violation') {
                    throw new ImportUnavailable;
                }
                $rows[] = new PreparedImportRowData($record->number, null, $error->reason);
            }
        }

        return $rows;
    }
}
