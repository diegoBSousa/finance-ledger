<?php

declare(strict_types=1);

namespace App\Application\Dashboard\Data;

use App\Application\Dashboard\FinancialRevision;
use App\Domain\Shared\DecimalInteger;
use App\Domain\Shared\DomainViolation;
use App\Domain\Shared\Money;

final readonly class DashboardData
{
    public function __construct(public string $ownerUserId, public string $revision, public string $incomeMinor, public string $expenseMinor, public string $balanceMinor, public string $transactionCount)
    {
        DecimalInteger::positive($ownerUserId);
        FinancialRevision::validate($revision);
        $income = Money::fromDecimal($incomeMinor);
        $expense = Money::fromDecimal($expenseMinor);
        if ($income->minor < 0 || $expense->minor < 0 || $income->toDecimal() !== $incomeMinor || $expense->toDecimal() !== $expenseMinor
            || $income->subtract($expense)->toDecimal() !== $balanceMinor || preg_match('/\A(?:0|[1-9][0-9]*)\z/', $transactionCount) !== 1) {
            throw new DomainViolation('invalid_dashboard', 'Dashboard totals must be exact and consistent.');
        }
    }
}
