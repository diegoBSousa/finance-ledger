<?php

declare(strict_types=1);

namespace App\Application\Balances\Contracts;

use App\Application\Balances\Data\BalancePageData;
use App\Application\Balances\Data\BalancePageQueryData;
use App\Application\Balances\Data\PendingBalancesData;
use App\Application\Balances\Data\RefreshBalanceData;
use App\Application\Balances\Data\RefreshedBalanceData;

interface BalanceRepository
{
    /** Only the owner's financial accounts, including inactive historical accounts; stable ID order. */
    public function page(BalancePageQueryData $query): BalancePageData;

    /** Own committed transaction. Returns null only when skipLocked is true and the projection is locked. */
    public function refresh(RefreshBalanceData $request): ?RefreshedBalanceData;

    /** Internal scan of at most ten pending accounts (including technical accounts), after an ID cursor. */
    public function pending(string $afterAccountId): PendingBalancesData;
}
