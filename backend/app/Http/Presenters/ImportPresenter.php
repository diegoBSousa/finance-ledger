<?php

declare(strict_types=1);

namespace App\Http\Presenters;

use App\Application\Imports\Data\ImportData;
use App\Application\Imports\Data\ListImportsResponse;

final class ImportPresenter
{
    /** @return array<string,mixed> */
    public static function import(ImportData $import): array
    {
        return [
            'id' => $import->id, 'original_name' => $import->originalName, 'file_size_bytes' => $import->fileSizeBytes,
            'status' => $import->status, 'processed_rows' => $import->processedRows, 'inserted_rows' => $import->insertedRows,
            'duplicate_rows' => $import->duplicateRows, 'rejected_rows' => $import->rejectedRows,
            'created_at' => $import->createdAt, 'prepared_at' => $import->preparedAt, 'started_at' => $import->startedAt,
            'finished_at' => $import->finishedAt, 'error_code' => $import->errorCode, 'status_url' => '/api/v1/imports/'.$import->id,
        ];
    }

    /** @return array<string,mixed> */
    public static function page(ListImportsResponse $response): array
    {
        $perPage = $response->pagination->perPage;
        $total = $response->page->total;
        $last = max(1, intdiv($total, $perPage) + (int) ($total % $perPage !== 0));

        return ['data' => array_map(self::import(...), $response->page->imports), 'meta' => [
            'current_page' => $response->pagination->page, 'per_page' => $perPage, 'total' => $total,
            'last_page' => $last, 'has_next_page' => $response->pagination->page < $last,
        ]];
    }
}
