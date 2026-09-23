<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Accounting\Contracts\JournalRepository;
use App\Application\Accounting\Data\PostingBatchData;
use App\Application\Imports\ChunkLimits;
use App\Application\Imports\Contracts\ImportChunkRepository;
use App\Application\Imports\Data\CommitImportChunkData;
use App\Application\Imports\Data\ImportChunkSourceData;
use App\Application\Imports\Data\StoredImportFileData;
use App\Application\Imports\ImportUnavailable;
use App\Domain\Shared\DecimalInteger;
use App\Domain\Shared\DomainViolation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use PDOException;
use stdClass;

final readonly class MysqlImportChunkRepository implements ImportChunkRepository
{
    public function __construct(private JournalRepository $journals) {}

    public function claim(string $deliveryId): ?ImportChunkSourceData
    {
        $this->requireOwnTransaction();
        try {
            return DB::transaction(function () use ($deliveryId): ?ImportChunkSourceData {
                $delivery = DB::table('outbox_deliveries')->where('id', $deliveryId)->lockForUpdate()->first();
                if ($delivery === null || in_array($delivery->status, ['acknowledged', 'failed'], true)) {
                    return null;
                }
                $event = DB::table('outbox_events')->where('id', $delivery->event_id)->first();
                try {
                    $payload = json_decode($event->payload, true, flags: JSON_THROW_ON_ERROR);
                    if ($delivery->consumer !== 'csv-importer' || $event->event_type !== 'ImportChunkRequested' || (int) $event->event_version !== 1
                        || ! is_array($payload) || ! is_string($payload['import_id'] ?? null) || ! is_string($payload['checkpoint_version'] ?? null)
                        || (string) DecimalInteger::positive($payload['import_id']) !== $payload['import_id']
                        || preg_match('/\A(?:0|[1-9][0-9]{0,8})\z/', $payload['checkpoint_version']) !== 1) {
                        $this->unsupported($deliveryId);

                        return null;
                    }
                } catch (JsonException|DomainViolation) {
                    $this->unsupported($deliveryId);

                    return null;
                }
                $row = $this->import($payload['import_id']);
                if ($row === null || $row->prepared_at === null) {
                    throw new ImportUnavailable;
                }
                if (in_array($row->status, ['completed', 'completed_with_errors', 'failed'], true)
                    || (int) $payload['checkpoint_version'] < (int) $row->checkpoint_version) {
                    $this->acknowledge($deliveryId);

                    return null;
                }
                if ($payload['checkpoint_version'] !== (string) $row->checkpoint_version) {
                    $this->unsupported($deliveryId);

                    return null;
                }
                if ((bool) $row->lease_active) {
                    return null;
                }
                if ((int) $row->chunk_attempts >= ChunkLimits::ATTEMPTS) {
                    $this->terminalFailure($row, $deliveryId, 'import_chunk_attempts_exhausted');

                    return null;
                }
                $token = (string) Str::uuid();
                DB::table('imports')->where('id', $row->id)->update([
                    'status' => 'processing', 'chunk_attempts' => DB::raw('chunk_attempts + 1'),
                    'started_at' => DB::raw('COALESCE(started_at, CURRENT_TIMESTAMP(6))'),
                    'lease_token' => $token, 'lease_expires_at' => DB::raw('CURRENT_TIMESTAMP(6) + INTERVAL 180 SECOND'),
                    'heartbeat_at' => DB::raw('CURRENT_TIMESTAMP(6)'), 'updated_at' => DB::raw('CURRENT_TIMESTAMP(6)'),
                ]);

                return new ImportChunkSourceData($deliveryId, (string) $row->id, (string) $row->uploaded_by_user_id,
                    new StoredImportFileData($row->file_path, (int) $row->file_size_bytes, $row->file_checksum),
                    (int) $row->byte_offset, (string) $row->last_record_number, (string) $row->checkpoint_version, $token,
                    $row->file_block_hashes === null ? null : json_decode($row->file_block_hashes, true, flags: JSON_THROW_ON_ERROR));
            }, attempts: 3);
        } catch (PDOException|JsonException) {
            throw new ImportUnavailable;
        }
    }

    public function commit(CommitImportChunkData $request): void
    {
        $this->requireOwnTransaction();
        try {
            DB::transaction(function () use ($request): void {
                $source = $request->source;
                $row = $this->owned($source) ?? throw new ImportUnavailable;
                $entries = [];
                foreach ($request->rows as $result) {
                    if ($result->posting !== null) {
                        $entries[] = $result->posting;
                    }
                }
                $posted = $entries === [] ? [] : $this->journals->post(new PostingBatchData($source->actorUserId, $entries))->entries;
                if (count($posted) !== count($entries)) {
                    throw new ImportUnavailable;
                }
                $results = [];
                $index = $inserted = $duplicates = $rejected = 0;
                foreach ($request->rows as $result) {
                    $posting = $result->posting === null ? null : $posted[$index++];
                    if ($posting !== null && ($posting->sourceRowHash !== $result->posting->sourceRowHash
                        || ! in_array($posting->status, ['inserted', 'duplicate'], true))) {
                        throw new ImportUnavailable;
                    }
                    $status = $posting->status ?? 'rejected';
                    $inserted += (int) ($status === 'inserted');
                    $duplicates += (int) ($status === 'duplicate');
                    $rejected += (int) ($status === 'rejected');
                    $results[] = ['import_id' => $source->importId, 'source_record_number' => $result->recordNumber,
                        'source_row_hash' => $posting?->sourceRowHash, 'status' => $status,
                        'journal_entry_id' => $posting?->journalEntryId, 'error_code' => $result->errorCode,
                        'error_message' => $result->errorCode === null ? null : 'The CSV record was rejected.'];
                }
                if ($results !== []) {
                    DB::table('import_rows')->insert($results);
                }
                $totals = ['processed_rows' => (string) ((int) $row->processed_rows + count($results)),
                    'inserted_rows' => (string) ((int) $row->inserted_rows + $inserted),
                    'duplicate_rows' => (string) ((int) $row->duplicate_rows + $duplicates),
                    'rejected_rows' => (string) ((int) $row->rejected_rows + $rejected)];
                $generation = (string) ((int) $source->checkpointVersion + 1);
                $status = $request->chunk->eof ? ((int) $totals['rejected_rows'] > 0 ? 'completed_with_errors' : 'completed') : 'processing';
                DB::table('imports')->where('id', $source->importId)->update($totals + [
                    'byte_offset' => $request->chunk->nextByteOffset, 'last_record_number' => $request->chunk->lastRecordNumber,
                    'checkpoint_version' => $generation, 'status' => $status, 'chunk_attempts' => 0,
                    'file_block_hashes' => json_encode($request->blockHashes, JSON_THROW_ON_ERROR),
                    'lease_token' => null, 'lease_expires_at' => null, 'last_error' => null,
                    'heartbeat_at' => DB::raw('CURRENT_TIMESTAMP(6)'), 'updated_at' => DB::raw('CURRENT_TIMESTAMP(6)'),
                    'finished_at' => $request->chunk->eof ? DB::raw('CURRENT_TIMESTAMP(6)') : null,
                ]);
                if ($request->chunk->eof) {
                    $this->event('ImportCompleted', ['import_id' => $source->importId, 'status' => $status] + $totals);
                } else {
                    $this->event('ImportChunkRequested', ['import_id' => $source->importId, 'checkpoint_version' => $generation], 'csv-importer');
                }
                $this->acknowledge($source->deliveryId);
            }, attempts: 3);
        } catch (PDOException) {
            throw new ImportUnavailable;
        }
    }

    public function fail(ImportChunkSourceData $source, string $errorCode, bool $retryable): bool
    {
        $this->requireOwnTransaction();
        try {
            return DB::transaction(function () use ($source, $errorCode, $retryable): bool {
                $row = $this->owned($source);
                if ($row === null) {
                    return false;
                }
                if (! $retryable || (int) $row->chunk_attempts >= ChunkLimits::ATTEMPTS) {
                    $this->terminalFailure($row, $source->deliveryId, $retryable ? 'import_chunk_attempts_exhausted' : $errorCode);

                    return true;
                }
                DB::table('imports')->where('id', $source->importId)->update([
                    'lease_token' => null, 'lease_expires_at' => null, 'last_error' => $errorCode,
                    'heartbeat_at' => DB::raw('CURRENT_TIMESTAMP(6)'), 'updated_at' => DB::raw('CURRENT_TIMESTAMP(6)'),
                ]);

                return false;
            }, attempts: 3);
        } catch (PDOException) {
            throw new ImportUnavailable;
        }
    }

    private function import(string $id): ?stdClass
    {
        return DB::table('imports')->where('id', $id)->select('imports.*')
            ->selectRaw('lease_token IS NOT NULL AND lease_expires_at > CURRENT_TIMESTAMP(6) AS lease_active')->lockForUpdate()->first();
    }

    private function owned(ImportChunkSourceData $source): ?stdClass
    {
        $delivery = DB::table('outbox_deliveries')->where('id', $source->deliveryId)->lockForUpdate()->first();
        if ($delivery === null || $delivery->consumer !== 'csv-importer' || in_array($delivery->status, ['failed', 'acknowledged'], true)
            || ! DB::table('outbox_events')->where('id', $delivery->event_id)->where('event_type', 'ImportChunkRequested')->where('event_version', 1)
                ->where('payload->import_id', $source->importId)->where('payload->checkpoint_version', $source->checkpointVersion)->exists()) {
            return null;
        }
        $row = $this->import($source->importId);
        if ($row === null || $row->status !== 'processing' || ! $row->lease_active || $row->lease_token !== $source->leaseToken
            || (string) $row->checkpoint_version !== $source->checkpointVersion || (int) $row->byte_offset !== $source->byteOffset
            || (string) $row->last_record_number !== $source->lastRecordNumber || (string) $row->uploaded_by_user_id !== $source->actorUserId) {
            return null;
        }

        return $row;
    }

    private function terminalFailure(stdClass $row, string $deliveryId, string $code): void
    {
        DB::table('imports')->where('id', $row->id)->update(['status' => 'failed', 'last_error' => $code,
            'lease_token' => null, 'lease_expires_at' => null, 'finished_at' => DB::raw('CURRENT_TIMESTAMP(6)'), 'updated_at' => DB::raw('CURRENT_TIMESTAMP(6)')]);
        $this->event('ImportFailed', ['import_id' => (string) $row->id, 'error_code' => $code,
            'checkpoint_version' => (string) $row->checkpoint_version, 'processed_rows' => (string) $row->processed_rows]);
        $this->acknowledge($deliveryId);
    }

    /** @param array<string,string> $payload */
    private function event(string $type, array $payload, ?string $consumer = null): void
    {
        $id = DB::table('outbox_events')->insertGetId(['event_type' => $type, 'event_version' => 1, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
        if ($consumer !== null) {
            DB::table('outbox_deliveries')->insert(['event_id' => $id, 'consumer' => $consumer]);
        }
    }

    private function acknowledge(string $id): void
    {
        DB::table('outbox_deliveries')->where('id', $id)->update(['status' => 'acknowledged',
            'acknowledged_at' => DB::raw('CURRENT_TIMESTAMP(6)'), 'lease_token' => null, 'lease_expires_at' => null, 'last_error' => null]);
    }

    private function unsupported(string $id): void
    {
        DB::table('outbox_deliveries')->where('id', $id)->update(['status' => 'failed', 'last_error' => 'unsupported_chunk_event',
            'lease_token' => null, 'lease_expires_at' => null]);
    }

    private function requireOwnTransaction(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new ImportUnavailable;
        }
    }
}
