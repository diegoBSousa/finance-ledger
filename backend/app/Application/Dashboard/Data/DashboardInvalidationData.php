<?php

declare(strict_types=1);

namespace App\Application\Dashboard\Data;

final readonly class DashboardInvalidationData
{
    public function __construct(public string $deliveryId, public string $eventId, public string $ownerUserId, public string $revision) {}
}
