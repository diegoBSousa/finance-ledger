<?php

declare(strict_types=1);

namespace App\Application\Balances\Data;

use App\Application\Pagination\PageRequest;

final readonly class ListAccountBalancesRequest
{
    public function __construct(public string $actorUserId, public PageRequest $pagination, public ?string $accountNumber = null) {}
}
