<?php

declare(strict_types=1);

namespace App\Application\Imports\Contracts;

use App\Application\Imports\Data\StoredImportFileData;
use App\Application\Imports\Data\ValidatedImportFileData;

interface ImportFileStorage
{
    /** Copies a trusted server-side temporary file using bounded memory; verifies exact bytes. */
    public function store(string $temporaryPath): StoredImportFileData;

    public function delete(string $path): void;

    /** Rechecks the complete checksum and validates the bounded CSV header. */
    public function validate(StoredImportFileData $file): ValidatedImportFileData;
}
