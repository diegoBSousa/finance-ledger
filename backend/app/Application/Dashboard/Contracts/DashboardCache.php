<?php

declare(strict_types=1);

namespace App\Application\Dashboard\Contracts;

use App\Application\Dashboard\Data\DashboardData;

interface DashboardCache
{
    public function get(string $ownerUserId, string $revision): ?DashboardData;

    public function put(DashboardData $data): void;

    /** Remove only cached revisions older than this event; repeated or older events are harmless. */
    public function invalidate(string $ownerUserId, string $revision): void;
}
