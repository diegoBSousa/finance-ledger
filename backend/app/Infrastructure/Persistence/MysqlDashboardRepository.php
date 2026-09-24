<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Dashboard\Contracts\DashboardRepository;
use App\Application\Dashboard\Data\DashboardData;
use App\Application\Dashboard\FinancialRevision;
use App\Domain\Shared\Money;
use Illuminate\Database\Connection;

final readonly class MysqlDashboardRepository implements DashboardRepository
{
    public function __construct(private ConsistentRead $read) {}

    public function revision(string $ownerUserId): string
    {
        return $this->read->run(fn (Connection $db) => FinancialRevision::validate((string) ($db->table('financial_states')->where('owner_user_id', $ownerUserId)->value('revision') ?? '0')));
    }

    public function snapshot(string $ownerUserId): DashboardData
    {
        return $this->read->run(function (Connection $db) use ($ownerUserId): DashboardData {
            // First consistent read establishes the snapshot used by the aggregate below.
            $revision = (string) ($db->table('financial_states')->where('owner_user_id', $ownerUserId)->value('revision') ?? '0');
            $totals = $db->table('journal_entries as j')->join('ledger_entries as l', function ($join): void {
                $join->on('l.journal_entry_id', '=', 'j.id')->on('l.account_id', '=', 'j.financial_account_id');
            })->where('j.owner_user_id', $ownerUserId)
                ->selectRaw("COALESCE(SUM(CASE WHEN j.movement_type = 'income' THEN l.amount_minor ELSE 0 END), 0) AS income")
                ->selectRaw("COALESCE(SUM(CASE WHEN j.movement_type = 'expense' THEN l.amount_minor ELSE 0 END), 0) AS expense")
                ->selectRaw('COUNT(*) AS operations')->first();
            $income = Money::fromDecimal((string) $totals->income);
            $expense = Money::fromDecimal((string) $totals->expense);

            return new DashboardData($ownerUserId, $revision, $income->toDecimal(), $expense->toDecimal(),
                $income->subtract($expense)->toDecimal(), (string) $totals->operations);
        });
    }
}
