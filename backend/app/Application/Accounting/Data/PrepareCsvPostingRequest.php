<?php

declare(strict_types=1);

namespace App\Application\Accounting\Data;

use App\Application\Imports\Data\CsvRowData;

final readonly class PrepareCsvPostingRequest
{
    // actorUserId must come from the trusted authentication/import context.
    public function __construct(public string $actorUserId, public CsvRowData $row) {}
}
