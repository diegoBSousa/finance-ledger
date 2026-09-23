<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Application\Outbox\Contracts\OutboxPublisher;
use App\Application\Outbox\Data\OutboxDeliveryData;
use App\Application\Outbox\PublicationUnavailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

final class RedisOutboxPublisher implements OutboxPublisher
{
    public function publish(OutboxDeliveryData $delivery): void
    {
        if (DB::transactionLevel() !== 0 || ! in_array($delivery->consumer, ['import-preparer', 'csv-importer'], true)) {
            throw new PublicationUnavailable;
        }
        try {
            // Publish immediately after the relay's claim commit, never via deferred afterCommit callbacks.
            $job = $delivery->consumer === 'import-preparer' ? new PrepareImportJob($delivery->id) : new ProcessImportChunkJob($delivery->id);
            Queue::connection('outbox')->push($job, '', 'imports');
        } catch (Throwable) {
            throw new PublicationUnavailable;
        }
    }
}
