<?php

declare(strict_types=1);

namespace App\Http\Presenters;

use App\Application\Accounts\Data\ListAccountsResponse;
use App\Domain\Accounting\Data\AccountData;

final class AccountPresenter
{
    /** @return array<string,mixed> */
    public static function page(ListAccountsResponse $response): array
    {
        return ['data' => array_map(fn (AccountData $row) => ['id' => $row->id, 'account_number' => $row->externalNumber,
            'currency' => $row->currency, 'active' => $row->active], $response->accounts),
            'meta' => PagePresenter::meta($response->pagination, $response->total)];
    }
}
