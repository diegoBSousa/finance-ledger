<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Imports\Contracts\ImportRepository;
use App\Application\Imports\Data\ImportData;
use App\Application\Imports\Data\ImportPageData;
use App\Application\Imports\Data\ImportPageQueryData;
use App\Application\Imports\Data\ImportRegistrationData;
use App\Application\Imports\ImportUnavailable;
use App\Infrastructure\Persistence\Models\ImportRecord;
use Illuminate\Support\Facades\DB;
use PDOException;

final class MysqlImportRepository implements ImportRepository
{
    public function register(ImportRegistrationData $request): ImportData
    {
        if (DB::transactionLevel() !== 0) {
            throw new ImportUnavailable;
        }
        try {
            return DB::transaction(function () use ($request): ImportData {
                $id = DB::table('imports')->insertGetId([
                    'uploaded_by_user_id' => $request->actorUserId, 'file_path' => $request->file->path,
                    'file_size_bytes' => $request->file->sizeBytes, 'file_checksum' => $request->file->checksum,
                    'original_name' => $request->originalName,
                ]);
                $event = DB::table('outbox_events')->insertGetId([
                    'event_type' => 'ImportRequested', 'event_version' => 1,
                    'payload' => json_encode(['import_id' => (string) $id], JSON_THROW_ON_ERROR),
                ]);
                DB::table('outbox_deliveries')->insert(['event_id' => $event, 'consumer' => 'import-preparer']);

                return ImportRecord::query()->findOrFail($id)->toData();
            }, attempts: 3);
        } catch (PDOException) {
            throw new ImportUnavailable;
        }
    }

    public function find(string $actorUserId, string $importId): ?ImportData
    {
        try {
            return ImportRecord::query()->where('uploaded_by_user_id', $actorUserId)->find($importId)?->toData();
        } catch (PDOException) {
            throw new ImportUnavailable;
        }
    }

    public function page(ImportPageQueryData $query): ImportPageData
    {
        try {
            $rows = ImportRecord::query()->where('uploaded_by_user_id', $query->actorUserId);
            $total = (clone $rows)->count();

            return new ImportPageData($rows->orderByDesc('id')->offset($query->pagination->offset())
                ->limit($query->pagination->perPage)->get()->map(fn (ImportRecord $row) => $row->toData())->all(), $total);
        } catch (PDOException) {
            throw new ImportUnavailable;
        }
    }

    public function referencesFile(string $path): bool
    {
        if (DB::transactionLevel() !== 0) {
            throw new ImportUnavailable;
        }
        try {
            return DB::table('imports')->where('file_path', $path)->exists();
        } catch (PDOException) {
            throw new ImportUnavailable;
        }
    }
}
