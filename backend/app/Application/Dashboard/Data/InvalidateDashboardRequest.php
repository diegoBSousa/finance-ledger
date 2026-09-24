<?php

declare(strict_types=1);

namespace App\Application\Dashboard\Data;

final readonly class InvalidateDashboardRequest
{
    public function __construct(public string $deliveryId) {}
}
