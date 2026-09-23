<?php

declare(strict_types=1);

namespace App\Application\Imports\Data;

final readonly class ImportSourceData
{
    public function __construct(public ImportData $import, public StoredImportFileData $file, public string $leaseToken) {}
}
