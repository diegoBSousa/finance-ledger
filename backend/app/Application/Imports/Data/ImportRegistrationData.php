<?php

declare(strict_types=1);

namespace App\Application\Imports\Data;

final readonly class ImportRegistrationData
{
    public function __construct(public string $actorUserId, public StoredImportFileData $file, public string $originalName) {}
}
