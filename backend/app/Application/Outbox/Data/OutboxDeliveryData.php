<?php

declare(strict_types=1);

namespace App\Application\Outbox\Data;

final readonly class OutboxDeliveryData
{
    public function __construct(public string $id, public string $eventId, public string $consumer,
        public string $eventType, public int $eventVersion, public string $payload, public int $attempts,
        public string $leaseToken) {}
}
