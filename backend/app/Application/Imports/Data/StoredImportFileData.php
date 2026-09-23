<?php

declare(strict_types=1);

namespace App\Application\Imports\Data;

final readonly class StoredImportFileData
{
    public function __construct(public string $path, public int $sizeBytes, public string $checksum) {}
}
