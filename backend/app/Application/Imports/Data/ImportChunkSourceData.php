<?php

declare(strict_types=1);

namespace App\Application\Imports\Data;

final readonly class ImportChunkSourceData
{
    /** @param list<string>|null $blockHashes */
    public function __construct(
        public string $deliveryId,
        public string $importId,
        public string $actorUserId,
        public StoredImportFileData $file,
        public int $byteOffset,
        public string $lastRecordNumber,
        public string $checkpointVersion,
        public string $leaseToken,
        public ?array $blockHashes,
    ) {}
}
