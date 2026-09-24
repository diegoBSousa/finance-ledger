<?php

declare(strict_types=1);

namespace App\Application\Dashboard\Contracts;

use App\Application\Dashboard\Data\DashboardInvalidationData;

interface DashboardInvalidationRepository
{
    /** Null for acknowledged/failed/unsupported deliveries. Unsupported events are marked failed. */
    public function pending(string $deliveryId): ?DashboardInvalidationData;

    /** Called only after Redis confirms invalidation; safe to repeat. */
    public function acknowledge(DashboardInvalidationData $event): void;
}
