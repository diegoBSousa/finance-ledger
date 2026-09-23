<?php

declare(strict_types=1);

namespace App\Application\Imports\Data;

final readonly class UploadCsvRequest
{
    public function __construct(public string $actorUserId, public string $temporaryPath, public string $originalName) {}
}
