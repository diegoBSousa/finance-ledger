<?php

declare(strict_types=1);

namespace App\Application\Transactions\Data;

use App\Application\Pagination\PageRequest;

final readonly class ListTransactionsResponse
{
    public function __construct(public TransactionPageData $page, public PageRequest $pagination) {}
}
