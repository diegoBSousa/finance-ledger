<?php

declare(strict_types=1);

namespace App\Application\Imports\Data;

final readonly class CsvRecordData
{
    public function __construct(public string $number, public ?CsvRowData $row, public ?string $errorCode = null) {}
}
