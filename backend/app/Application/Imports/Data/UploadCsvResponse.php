<?php

declare(strict_types=1);

namespace App\Application\Imports\Data;

final readonly class UploadCsvResponse
{
    public function __construct(public ImportData $import) {}
}
