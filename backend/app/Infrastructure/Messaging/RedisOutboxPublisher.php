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
        if (DB::transactionLevel() !== 0 || $delivery->consumer !== 'import-preparer') {
            throw new PublicationUnavailable;
        }
        try {
            // Publish immediately after the relay's claim commit, never via deferred afterCommit callbacks.
            Queue::connection('outbox')->push(new PrepareImportJob($delivery->id), '', 'imports');
        } catch (Throwable) {
            throw new PublicationUnavailable;
        }
    }
}
