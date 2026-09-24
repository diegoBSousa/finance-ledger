<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Imports\Contracts\ImportRowsRepository;
use App\Application\Imports\Data\ImportRowsPageData;
use App\Application\Imports\Data\ImportRowsQueryData;
use App\Infrastructure\Persistence\Models\ImportRowRecord;
use Illuminate\Database\Connection;

final readonly class MysqlImportRowsRepository implements ImportRowsRepository
{
    public function __construct(private ConsistentRead $read) {}

    public function page(ImportRowsQueryData $query): ?ImportRowsPageData
    {
        return $this->read->run(function (Connection $db) use ($query): ?ImportRowsPageData {
            if (! $db->table('imports')->where('id', $query->importId)->where('uploaded_by_user_id', $query->actorUserId)->exists()) {
                return null;
            }
            $rows = ImportRowRecord::on(ConsistentRead::CONNECTION)->where('import_id', $query->importId);
            if ($query->status !== null) {
                $rows->where('status', $query->status);
            }
            $total = (clone $rows)->count();

            return new ImportRowsPageData($rows->orderBy('source_record_number')->offset($query->pagination->offset())
                ->limit($query->pagination->perPage)->get()->map(fn (ImportRowRecord $row) => $row->toData())->all(), $total);
        });
    }
}
