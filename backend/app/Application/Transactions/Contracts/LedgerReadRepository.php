<?php

declare(strict_types=1);

namespace App\Application\Transactions\Contracts;

use App\Application\Transactions\Data\TransactionPageData;
use App\Application\Transactions\Data\TransactionQueryData;

interface LedgerReadRepository
{
    /** Descending financial date/ID; count and rows share a snapshot. Unknown/foreign account throws AccountNotFound. */
    public function page(TransactionQueryData $query): TransactionPageData;
}
