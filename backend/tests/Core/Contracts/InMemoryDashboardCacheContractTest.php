<?php

declare(strict_types=1);

namespace Tests\Core\Contracts;

use App\Application\Dashboard\Contracts\DashboardCache;
use Tests\Doubles\InMemoryDashboardCache;

final class InMemoryDashboardCacheContractTest extends DashboardCacheContract
{
    protected function cache(): DashboardCache
    {
        return new InMemoryDashboardCache;
    }
}
