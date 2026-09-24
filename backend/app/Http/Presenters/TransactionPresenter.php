<?php

declare(strict_types=1);

namespace App\Http\Presenters;

use App\Application\Transactions\Data\ListTransactionsResponse;
use App\Application\Transactions\Data\TransactionData;

final class TransactionPresenter
{
    /** @return array<string,mixed> */
    public static function page(ListTransactionsResponse $response): array
    {
        return ['data' => array_map(fn (TransactionData $row) => ['id' => $row->id, 'account_number' => $row->accountNumber,
            'transaction_date' => $row->transactionDate, 'description' => $row->description, 'type' => $row->type,
            'amount_minor' => $row->amountMinor, 'currency' => $row->currency, 'created_at' => $row->createdAt], $response->page->transactions),
            'meta' => PagePresenter::meta($response->pagination, $response->page->total)];
    }
}
