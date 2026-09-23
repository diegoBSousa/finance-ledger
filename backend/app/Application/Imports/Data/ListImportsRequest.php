<?php

declare(strict_types=1);

namespace App\Application\Imports\Data;

use App\Application\Pagination\PageRequest;

final readonly class ListImportsRequest
{
    public function __construct(public string $actorUserId, public PageRequest $pagination) {}
}
