<?php

declare(strict_types=1);

namespace App\Application\Imports\Data;

final readonly class ImportRowData
{
    public function __construct(public string $id, public string $importId, public string $recordNumber, public string $status, public ?string $sourceRowHash, public ?string $journalEntryId, public ?string $errorCode) {}
}
