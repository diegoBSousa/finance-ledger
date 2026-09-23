<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Balances\AccountNotFound;
use App\Application\Balances\BalanceUnavailable;
use App\Application\Balances\Contracts\BalanceRepository;
use App\Application\Balances\Data\BalancePageData;
use App\Application\Balances\Data\BalancePageQueryData;
use App\Application\Balances\Data\PendingBalancesData;
use App\Application\Balances\Data\RefreshBalanceData;
use App\Application\Balances\Data\RefreshedBalanceData;
use App\Domain\Accounting\AccountKind;
use App\Domain\Shared\DecimalInteger;
use App\Domain\Shared\DomainViolation;
use App\Domain\Shared\Money;
use App\Infrastructure\Persistence\Models\AccountBalanceRecord;
use App\Infrastructure\Persistence\Models\AccountRecord;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PDOException;

final class MysqlBalanceRepository implements BalanceRepository
{
    public const string CONNECTION = 'balance_projection';

    private function connection(): Connection
    {
        $connection = DB::connection(self::CONNECTION);
        // Never participate in a caller's snapshot or return an uncommitted projection.
        if ($connection->transactionLevel() !== 0) {
            throw new BalanceUnavailable;
        }

        return $connection;
    }

    public function page(BalancePageQueryData $query): BalancePageData
    {
        try {
            $this->connection();
            $accounts = AccountRecord::on(self::CONNECTION)->where('owner_user_id', $query->ownerUserId)->where('kind', 'asset');
            if ($query->accountNumber !== null) {
                $accounts->where('external_number', $query->accountNumber);
            }
            $total = (clone $accounts)->count();
            $page = $accounts->orderBy('id')->offset($query->pagination->offset())->limit($query->pagination->perPage)->get();

            return new BalancePageData($page->map(fn (AccountRecord $account) => $account->toData())->all(), $total);
        } catch (PDOException) {
            throw new BalanceUnavailable;
        }
    }

    public function refresh(RefreshBalanceData $request): ?RefreshedBalanceData
    {
        try {
            $connection = $this->connection();

            return $connection->transaction(function () use ($connection, $request): ?RefreshedBalanceData {
                $account = AccountRecord::on(self::CONNECTION)->where('owner_user_id', $request->ownerUserId)->find($request->accountId);
                if ($account === null) {
                    throw new AccountNotFound;
                }
                $query = AccountBalanceRecord::on(self::CONNECTION)->whereKey($request->accountId);
                $record = (clone $query)->lock($request->skipLocked ? 'FOR UPDATE SKIP LOCKED' : 'FOR UPDATE')->first();
                if ($record === null) {
                    if ($request->skipLocked && $query->exists()) {
                        return null;
                    }
                    throw new BalanceUnavailable;
                }
                if (! $record->staled && $record->ledger_version === $record->calculated_version) {
                    return new RefreshedBalanceData($record->toData($account->toData()), false);
                }
                // SUM(BIGINT) is returned as an exact decimal string; validate BEFORE any integer cast.
                // This read intentionally takes no ledger/owner locks: the projection coordinates writers.
                $totals = $connection->table('ledger_entries')->where('account_id', $request->accountId)
                    ->selectRaw("COALESCE(SUM(CASE WHEN side = 'debit' THEN amount_minor ELSE 0 END), 0) AS debits")
                    ->selectRaw("COALESCE(SUM(CASE WHEN side = 'credit' THEN amount_minor ELSE 0 END), 0) AS credits")
                    ->first();
                $debits = Money::fromDecimal((string) $totals->debits);
                $credits = Money::fromDecimal((string) $totals->credits);
                $balance = AccountKind::from($account->kind)->balance($debits, $credits);
                $connection->table('account_balances')->where('account_id', $request->accountId)->update([
                    'debit_total_minor' => $debits->toDecimal(), 'credit_total_minor' => $credits->toDecimal(),
                    'balance_minor' => $balance->toDecimal(), 'calculated_version' => $record->ledger_version,
                    'staled' => false, 'calculated_at' => $connection->raw('CURRENT_TIMESTAMP(6)'),
                ]);
                $record->refresh();

                return new RefreshedBalanceData($record->toData($account->toData()), true);
            }, attempts: 3);
        } catch (PDOException|DomainViolation) {
            // Includes aggregate overflow. Roll back and preserve the dirty projection; never substitute zero.
            throw new BalanceUnavailable;
        }
    }

    public function pending(string $afterAccountId): PendingBalancesData
    {
        $cursor = $afterAccountId === '0' ? '0' : (string) DecimalInteger::positive($afterAccountId);
        try {
            $connection = $this->connection();
            $pending = $connection->table('account_balances')->where(function ($query): void {
                $query->where('staled', true)->orWhereColumn('ledger_version', '<>', 'calculated_version');
            });
            $stats = (clone $pending)->selectRaw('COUNT(*) AS pending_count, MIN(staled_since) AS oldest')->first();
            $ids = (clone $pending)->where('account_id', '>', $cursor)->orderBy('account_id')->limit(10)->pluck('account_id');
            $accounts = AccountRecord::on(self::CONNECTION)->whereIn('id', $ids)->orderBy('id')->get();

            return new PendingBalancesData($accounts->map(fn (AccountRecord $account) => $account->toData())->all(),
                (int) $stats->pending_count, $stats->oldest === null ? null : Carbon::parse($stats->oldest, 'UTC')->format('Y-m-d\TH:i:s.u\Z'));
        } catch (PDOException) {
            throw new BalanceUnavailable;
        }
    }
}
