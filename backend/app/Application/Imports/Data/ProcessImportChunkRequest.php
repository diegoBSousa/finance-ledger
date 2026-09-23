<?php

declare(strict_types=1);

namespace App\Application\Imports\Data;

final readonly class ProcessImportChunkRequest
{
    public function __construct(public string $deliveryId) {}
}
