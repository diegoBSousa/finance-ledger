<?php

declare(strict_types=1);

namespace App\Application\Outbox\Contracts;

use App\Application\Outbox\Data\ClaimedDeliveriesData;
use App\Application\Outbox\Data\OutboxDeliveryData;

interface OutboxRepository
{
    /** Up to ten supported deliveries: pending, expired publishing lease or unacknowledged publication. */
    public function claim(): ClaimedDeliveriesData;

    /** Conditional on lease ownership; must never overwrite an early consumer acknowledgment. */
    public function published(OutboxDeliveryData $delivery): void;

    public function retry(OutboxDeliveryData $delivery): void;
}
