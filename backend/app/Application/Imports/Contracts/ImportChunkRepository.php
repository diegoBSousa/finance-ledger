<?php

declare(strict_types=1);

namespace App\Application\Imports\Contracts;

use App\Application\Imports\Data\CommitImportChunkData;
use App\Application\Imports\Data\ImportChunkSourceData;

interface ImportChunkRepository
{
    /** Claim the expected generation; old, terminal and busy deliveries do no work. */
    public function claim(string $deliveryId): ?ImportChunkSourceData;

    /** Commit financial entries, row results, checkpoint, next intent and acknowledgment together. */
    public function commit(CommitImportChunkData $request): void;

    /** Release only this lease; return true when the import has been definitively failed. */
    public function fail(ImportChunkSourceData $source, string $errorCode, bool $retryable): bool;
}
