<?php

declare(strict_types=1);

namespace App\Application\Imports\Data;

final readonly class GetImportRequest
{
    public function __construct(public string $actorUserId, public string $importId) {}
}
