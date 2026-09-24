<?php

declare(strict_types=1);

namespace App\Application\Accounts\Data;

use App\Application\Pagination\PageRequest;
use App\Domain\Accounting\Data\AccountData;

final readonly class ListAccountsResponse
{
    /** @param list<AccountData> $accounts */
    public function __construct(public array $accounts, public int $total, public PageRequest $pagination) {}
}
