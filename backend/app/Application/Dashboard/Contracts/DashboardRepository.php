<?php

declare(strict_types=1);

namespace App\Application\Dashboard\Contracts;

use App\Application\Dashboard\Data\DashboardData;

interface DashboardRepository
{
    /** Current committed revision, independent of an ambient transaction. */
    public function revision(string $ownerUserId): string;

    /** Revision and all aggregates from one committed consistent snapshot. */
    public function snapshot(string $ownerUserId): DashboardData;
}
