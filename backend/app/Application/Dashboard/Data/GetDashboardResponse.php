<?php

declare(strict_types=1);

namespace App\Application\Dashboard\Data;

final readonly class GetDashboardResponse
{
    public function __construct(public DashboardData $dashboard) {}
}
