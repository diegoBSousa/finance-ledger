<?php

declare(strict_types=1);

namespace App\Application\Balances\Data;

use App\Domain\Accounting\Data\AccountData;

final readonly class BalancePageData
{
    /** @param list<AccountData> $accounts */
    public function __construct(public array $accounts, public int $total) {}
}
