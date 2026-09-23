<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Imports\Contracts\ImportPreparationRepository;
use App\Application\Imports\Data\ImportSourceData;
use App\Application\Imports\Data\StoredImportFileData;
use App\Application\Imports\ImportUnavailable;
use App\Infrastructure\Persistence\Models\ImportRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use PDOException;

final class MysqlImportPreparationRepository implements ImportPreparationRepository
{
    public function claim(string $deliveryId): ?ImportSourceData
    {
        $this->requireOwnTransaction();
        try {
            return DB::transaction(function () use ($deliveryId): ?ImportSourceData {
                $delivery = DB::table('outbox_deliveries')->where('id', $deliveryId)->lockForUpdate()->first();
                if ($delivery === null || in_array($delivery->status, ['acknowledged', 'failed'], true)) {
                    return null;
                }
                $event = DB::table('outbox_events')->where('id', $delivery->event_id)->first();
                $payload = json_decode($event->payload, true, flags: JSON_THROW_ON_ERROR);
                if ($delivery->consumer !== 'import-preparer' || $event->event_type !== 'ImportRequested' || (int) $event->event_version !== 1
                    || ! is_array($payload) || ! isset($payload['import_id']) || ! is_string($payload['import_id'])
                    || preg_match('/\A[1-9][0-9]{0,18}\z/', $payload['import_id']) !== 1) {
                    DB::table('outbox_deliveries')->where('id', $deliveryId)->update(['status' => 'failed', 'last_error' => 'unsupported_import_event', 'lease_token' => null, 'lease_expires_at' => null]);

                    return null;
                }
                $row = DB::table('imports')->where('id', $payload['import_id'])
                    ->select('imports.*')->selectRaw('lease_token IS NOT NULL AND lease_expires_at > CURRENT_TIMESTAMP(6) AS lease_active')
                    ->lockForUpdate()->first();
                if ($row === null) {
                    throw new ImportUnavailable;
                }
                if ($row->prepared_at !== null || $row->status === 'failed') {
                    $this->acknowledge($deliveryId);

                    return null;
                }
                if ((bool) $row->lease_active) {
                    return null;
                }
                $token = (string) Str::uuid();
                DB::table('imports')->where('id', $row->id)->update([
                    'lease_token' => $token, 'lease_expires_at' => DB::raw('CURRENT_TIMESTAMP(6) + INTERVAL 180 SECOND'),
                    'heartbeat_at' => DB::raw('CURRENT_TIMESTAMP(6)'),
                ]);

                return new ImportSourceData(ImportRecord::query()->findOrFail($row->id)->toData(),
                    new StoredImportFileData($row->file_path, (int) $row->file_size_bytes, $row->file_checksum), $token);
            }, attempts: 3);
        } catch (PDOException|JsonException) {
            throw new ImportUnavailable;
        }
    }

    public function complete(string $deliveryId, ImportSourceData $source, int $headerOffset): void
    {
        $this->finish($deliveryId, $source, $headerOffset, null);
    }

    public function reject(string $deliveryId, ImportSourceData $source, string $errorCode): void
    {
        $this->finish($deliveryId, $source, 0, $errorCode);
    }

    private function finish(string $deliveryId, ImportSourceData $source, int $offset, ?string $error): void
    {
        $this->requireOwnTransaction();
        try {
            DB::transaction(function () use ($deliveryId, $source, $offset, $error): void {
                $delivery = DB::table('outbox_deliveries')->where('id', $deliveryId)->lockForUpdate()->first();
                if ($delivery === null || $delivery->consumer !== 'import-preparer'
                    || ! DB::table('outbox_events')->where('id', $delivery->event_id)
                        ->where('event_type', 'ImportRequested')->where('event_version', 1)
                        ->where('payload->import_id', $source->import->id)->exists()) {
                    throw new ImportUnavailable;
                }
                $row = DB::table('imports')->where('id', $source->import->id)->where('lease_token', $source->leaseToken)
                    ->where('lease_expires_at', '>', DB::raw('CURRENT_TIMESTAMP(6)'))->lockForUpdate()->first();
                if ($row === null) {
                    throw new ImportUnavailable;
                }
                if ($error === null && ($offset < 1 || $offset > $row->file_size_bytes)) {
                    throw new ImportUnavailable;
                }
                $changes = ['lease_token' => null, 'lease_expires_at' => null, 'heartbeat_at' => DB::raw('CURRENT_TIMESTAMP(6)'), 'updated_at' => DB::raw('CURRENT_TIMESTAMP(6)')];
                if ($error === null) {
                    $changes += ['prepared_at' => DB::raw('CURRENT_TIMESTAMP(6)'), 'byte_offset' => $offset, 'last_record_number' => 1];
                    $event = DB::table('outbox_events')->insertGetId([
                        'event_type' => 'ImportChunkRequested', 'event_version' => 1,
                        'payload' => json_encode(['import_id' => $source->import->id, 'checkpoint_version' => '0'], JSON_THROW_ON_ERROR),
                    ]);
                    DB::table('outbox_deliveries')->insert(['event_id' => $event, 'consumer' => 'csv-importer']);
                } else {
                    $changes += ['status' => 'failed', 'last_error' => $error, 'finished_at' => DB::raw('CURRENT_TIMESTAMP(6)')];
                }
                DB::table('imports')->where('id', $source->import->id)->update($changes);
                $this->acknowledge($deliveryId);
            }, attempts: 3);
        } catch (PDOException) {
            throw new ImportUnavailable;
        }
    }

    public function release(ImportSourceData $source): void
    {
        try {
            DB::table('imports')->where('id', $source->import->id)->where('lease_token', $source->leaseToken)
                ->update(['lease_token' => null, 'lease_expires_at' => null]);
        } catch (PDOException) {
            throw new ImportUnavailable;
        }
    }

    private function acknowledge(string $deliveryId): void
    {
        DB::table('outbox_deliveries')->where('id', $deliveryId)->update([
            'status' => 'acknowledged', 'acknowledged_at' => DB::raw('CURRENT_TIMESTAMP(6)'),
            'lease_token' => null, 'lease_expires_at' => null, 'last_error' => null,
        ]);
    }

    private function requireOwnTransaction(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new ImportUnavailable;
        }
    }
}
