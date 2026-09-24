<?php

declare(strict_types=1);

namespace App\Application\Transactions\Data;

final readonly class TransactionPageData
{
    /** @param list<TransactionData> $transactions */
    public function __construct(public array $transactions, public int $total) {}
}
