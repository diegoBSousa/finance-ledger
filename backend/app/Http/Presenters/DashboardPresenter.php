<?php

declare(strict_types=1);

namespace App\Http\Presenters;

use App\Application\Dashboard\Data\GetDashboardResponse;

final class DashboardPresenter
{
    /** @return array<string,mixed> */
    public static function present(GetDashboardResponse $response): array
    {
        $data = $response->dashboard;

        return ['data' => ['currency' => 'BRL', 'income_minor' => $data->incomeMinor, 'expense_minor' => $data->expenseMinor,
            'balance_minor' => $data->balanceMinor, 'transaction_count' => $data->transactionCount, 'financial_revision' => $data->revision]];
    }
}
