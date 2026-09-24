<?php

declare(strict_types=1);

namespace App\Http\Presenters;

use App\Application\Imports\Data\ImportRowData;
use App\Application\Imports\Data\ListImportRowsResponse;

final class ImportRowPresenter
{
    /** @return array<string,mixed> */
    public static function page(ListImportRowsResponse $response): array
    {
        return ['data' => array_map(fn (ImportRowData $row) => ['id' => $row->id, 'source_record_number' => $row->recordNumber,
            'status' => $row->status, 'source_row_hash' => $row->sourceRowHash, 'journal_entry_id' => $row->journalEntryId,
            'error_code' => $row->errorCode, 'error_message' => $row->errorCode === null ? null : 'The CSV record was rejected.'], $response->page->rows),
            'meta' => PagePresenter::meta($response->pagination, $response->page->total)];
    }
}
