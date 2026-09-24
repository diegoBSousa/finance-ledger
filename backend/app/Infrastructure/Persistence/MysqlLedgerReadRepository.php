<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Balances\AccountNotFound;
use App\Application\Transactions\Contracts\LedgerReadRepository;
use App\Application\Transactions\Data\TransactionPageData;
use App\Application\Transactions\Data\TransactionQueryData;
use App\Infrastructure\Persistence\Models\JournalEntryRecord;
use Illuminate\Database\Connection;

final readonly class MysqlLedgerReadRepository implements LedgerReadRepository
{
    public function __construct(private ConsistentRead $read) {}

    public function page(TransactionQueryData $query): TransactionPageData
    {
        return $this->read->run(function (Connection $db) use ($query): TransactionPageData {
            $accountId = null;
            if ($query->accountNumber !== null) {
                $accountId = $db->table('accounts')->where('owner_user_id', $query->actorUserId)->where('kind', 'asset')
                    ->where('external_number', $query->accountNumber)->value('id') ?? throw new AccountNotFound;
            }
            $rows = JournalEntryRecord::on(ConsistentRead::CONNECTION)
                ->join('accounts as a', 'a.id', '=', 'journal_entries.financial_account_id')
                ->join('ledger_entries as l', function ($join): void {
                    $join->on('l.journal_entry_id', '=', 'journal_entries.id')->on('l.account_id', '=', 'journal_entries.financial_account_id');
                })->where('journal_entries.owner_user_id', $query->actorUserId);
            if ($accountId !== null) {
                $rows->where('financial_account_id', $accountId);
            }
            if ($query->dateFrom !== null) {
                $rows->where('posting_date', '>=', $query->dateFrom);
            }
            if ($query->dateTo !== null) {
                $rows->where('posting_date', '<=', $query->dateTo);
            }
            if ($query->type !== null) {
                $rows->where('movement_type', $query->type);
            }
            $total = (clone $rows)->count();
            $page = $rows->select('journal_entries.*', 'a.external_number as account_number', 'l.amount_minor')
                ->orderByDesc('posting_date')->orderByDesc('journal_entries.id')->offset($query->pagination->offset())
                ->limit($query->pagination->perPage)->get();

            return new TransactionPageData($page->map(fn (JournalEntryRecord $record) => $record->toData())->all(), $total);
        });
    }
}
