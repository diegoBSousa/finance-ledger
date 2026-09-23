<?php

declare(strict_types=1);

namespace App\Application\Accounting\Data;

use App\Application\Imports\Data\CsvRowData;

final readonly class PostCsvBatchRequest
{
    /** @param array<array-key, CsvRowData> $rows Already parsed rows; actorUserId comes from trusted context. */
    public function __construct(public string $actorUserId, public array $rows) {}
}
