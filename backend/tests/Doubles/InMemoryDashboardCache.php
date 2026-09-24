<?php

declare(strict_types=1);

namespace Tests\Doubles;

use App\Application\Dashboard\Contracts\DashboardCache;
use App\Application\Dashboard\Data\DashboardData;
use App\Application\Dashboard\FinancialRevision;

final class InMemoryDashboardCache implements DashboardCache
{
    /** @var array<string,DashboardData> */
    public array $items = [];

    public function get(string $ownerUserId, string $revision): ?DashboardData
    {
        $data = $this->items[$ownerUserId] ?? null;

        return $data?->revision === $revision ? $data : null;
    }

    public function put(DashboardData $data): void
    {
        $old = $this->items[$data->ownerUserId] ?? null;
        if ($old === null || FinancialRevision::compare($old->revision, $data->revision) <= 0) {
            $this->items[$data->ownerUserId] = $data;
        }
    }

    public function invalidate(string $ownerUserId, string $revision): void
    {
        $old = $this->items[$ownerUserId] ?? null;
        if ($old !== null && FinancialRevision::compare($old->revision, $revision) < 0) {
            unset($this->items[$ownerUserId]);
        }
    }
}
