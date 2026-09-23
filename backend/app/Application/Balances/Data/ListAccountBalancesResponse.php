<?php

declare(strict_types=1);

namespace App\Application\Balances\Data;

use App\Application\Pagination\PageRequest;

final readonly class ListAccountBalancesResponse
{
    /** @param list<AccountBalanceData> $balances */
    public function __construct(public array $balances, public PageRequest $pagination, public int $total) {}
}
