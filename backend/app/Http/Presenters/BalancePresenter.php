<?php

declare(strict_types=1);

namespace App\Http\Presenters;

use App\Application\Balances\Data\AccountBalanceData;
use App\Application\Balances\Data\ListAccountBalancesResponse;

final class BalancePresenter
{
    /** @return array<string, mixed> */
    public static function balance(AccountBalanceData $balance): array
    {
        return [
            'account_id' => $balance->account->id, 'account_number' => $balance->account->externalNumber,
            'active' => $balance->account->active, 'currency' => $balance->account->currency,
            'debit_total_minor' => $balance->debitTotalMinor, 'credit_total_minor' => $balance->creditTotalMinor,
            'balance_minor' => $balance->balanceMinor, 'ledger_version' => $balance->ledgerVersion,
            'calculated_version' => $balance->calculatedVersion, 'calculated_at' => $balance->calculatedAt,
        ];
    }

    /** @return array<string, mixed> */
    public static function page(ListAccountBalancesResponse $response): array
    {
        $page = $response->pagination;
        $lastPage = max(1, intdiv($response->total, $page->perPage) + (int) ($response->total % $page->perPage !== 0));

        return [
            'data' => array_map(self::balance(...), $response->balances),
            'meta' => [
                'current_page' => $page->page, 'per_page' => $page->perPage, 'total' => $response->total,
                'last_page' => $lastPage, 'has_next_page' => $page->page < $lastPage,
            ],
        ];
    }
}
