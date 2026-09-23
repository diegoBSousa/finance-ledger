<?php

declare(strict_types=1);

namespace App\Application\Outbox\Data;

final readonly class ClaimedDeliveriesData
{
    /** @param list<OutboxDeliveryData> $deliveries */
    public function __construct(public array $deliveries) {}
}
