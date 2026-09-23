<?php

declare(strict_types=1);

namespace App\Application\Imports\Contracts;

use App\Application\Imports\Data\ImportSourceData;

interface ImportPreparationRepository
{
    /** Claims a bounded lease, or returns null for an acknowledged/busy delivery. */
    public function claim(string $deliveryId): ?ImportSourceData;

    /** Atomically advance the header checkpoint, enqueue the first chunk and acknowledge preparation. */
    /** @param list<string> $blockHashes */
    public function complete(string $deliveryId, ImportSourceData $source, int $headerOffset, array $blockHashes = []): void;

    public function reject(string $deliveryId, ImportSourceData $source, string $errorCode): void;

    public function release(ImportSourceData $source): void;
}
