<?php

declare(strict_types=1);

namespace App\Application\Outbox\Contracts;

use App\Application\Outbox\Data\OutboxDeliveryData;

interface OutboxPublisher
{
    public function publish(OutboxDeliveryData $delivery): void;
}
