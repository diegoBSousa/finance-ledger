<?php

declare(strict_types=1);

namespace App\Application\Imports\Data;

final readonly class ImportData
{
    public function __construct(public string $id, public string $uploadedByUserId, public string $originalName, public int $fileSizeBytes,
        public string $status, public string $processedRows, public string $insertedRows,
        public string $duplicateRows, public string $rejectedRows, public string $createdAt,
        public ?string $preparedAt, public ?string $startedAt, public ?string $finishedAt, public ?string $errorCode) {}
}
