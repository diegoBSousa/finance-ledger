<?php

declare(strict_types=1);

namespace App\Application\Transactions\Data;

final readonly class ListTransactionsRequest
{
    public function __construct(public TransactionQueryData $query) {}
}
