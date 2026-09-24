<?php

declare(strict_types=1);

namespace App\Application\Dashboard\Data;

final readonly class InvalidateDashboardResponse
{
    public function __construct(public bool $processed) {}
}
