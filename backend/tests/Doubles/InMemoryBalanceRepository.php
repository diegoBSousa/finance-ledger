<?php

declare(strict_types=1);

namespace Tests\Doubles;

use App\Application\Accounting\Data\PostJournalData;
use App\Application\Balances\AccountNotFound;
use App\Application\Balances\BalanceUnavailable;
use App\Application\Balances\Contracts\BalanceRepository;
use App\Application\Balances\Data\AccountBalanceData;
use App\Application\Balances\Data\BalancePageData;
use App\Application\Balances\Data\BalancePageQueryData;
use App\Application\Balances\Data\PendingBalancesData;
use App\Application\Balances\Data\RefreshBalanceData;
use App\Application\Balances\Data\RefreshedBalanceData;
use App\Domain\Accounting\AccountKind;
use App\Domain\Accounting\Data\AccountData;
use App\Domain\Accounting\Data\PostingData;
use App\Domain\Shared\DomainViolation;
use App\Domain\Shared\Money;

final class InMemoryBalanceRepository implements BalanceRepository
{
    /** @var array<string, AccountBalanceData> */
    public array $balances = [];

    /** @var list<string> */
    public array $locked = [];

    /** @var list<string> */
    public array $failing = [];

    /** @var list<string> */
    public array $refreshed = [];

    /** @var list<PostingData> */
    private array $postings = [];

    /** @param list<AccountData> $accounts @param list<PostJournalData> $entries */
    public function __construct(array $accounts, array $entries = [])
    {
        foreach ($entries as $entry) {
            array_push($this->postings, ...$entry->journal->postings);
        }
        usort($accounts, fn (AccountData $a, AccountData $b) => (int) $a->id <=> (int) $b->id);
        foreach ($accounts as $account) {
            $count = count(array_filter($this->postings, fn (PostingData $p) => $p->accountId === $account->id));
            $this->balances[$account->id] = new AccountBalanceData($account, '0', '0', '0', (string) $count, '0', '2026-09-23T00:00:00.000000Z', $count > 0);
        }
    }

    public function page(BalancePageQueryData $query): BalancePageData
    {
        $accounts = array_values(array_map(fn (AccountBalanceData $b) => $b->account, array_filter($this->balances,
            fn (AccountBalanceData $b) => $b->account->ownerUserId === $query->ownerUserId && $b->account->kind === 'asset'
                && ($query->accountNumber === null || $b->account->externalNumber === $query->accountNumber))));

        return new BalancePageData(array_slice($accounts, $query->pagination->offset(), $query->pagination->perPage), count($accounts));
    }

    public function refresh(RefreshBalanceData $request): ?RefreshedBalanceData
    {
        $balance = $this->balances[$request->accountId] ?? null;
        if ($balance === null || $balance->account->ownerUserId !== $request->ownerUserId) {
            throw new AccountNotFound;
        }
        $this->refreshed[] = $request->accountId;
        if (in_array($request->accountId, $this->failing, true)) {
            throw new BalanceUnavailable;
        }
        if (in_array($request->accountId, $this->locked, true)) {
            if ($request->skipLocked) {
                return null;
            }
            throw new BalanceUnavailable;
        }
        if (! $balance->staled && $balance->calculatedVersion === $balance->ledgerVersion) {
            return new RefreshedBalanceData($balance, false);
        }
        try {
            $debits = $credits = Money::fromMinor(0);
            foreach ($this->postings as $posting) {
                if ($posting->accountId !== $request->accountId) {
                    continue;
                }
                $amount = Money::fromDecimal($posting->amountMinor);
                if ($posting->side === 'debit') {
                    $debits = $debits->add($amount);
                } else {
                    $credits = $credits->add($amount);
                }
            }
            $minor = AccountKind::from($balance->account->kind)->balance($debits, $credits)->toDecimal();
        } catch (DomainViolation) {
            throw new BalanceUnavailable;
        }
        $updated = new AccountBalanceData($balance->account, $debits->toDecimal(), $credits->toDecimal(), $minor,
            $balance->ledgerVersion, $balance->ledgerVersion, '2026-09-23T00:01:00.000000Z', false);
        $this->balances[$request->accountId] = $updated;

        return new RefreshedBalanceData($updated, true);
    }

    public function pending(string $afterAccountId): PendingBalancesData
    {
        $pending = array_filter($this->balances, fn (AccountBalanceData $b) => $b->staled || $b->ledgerVersion !== $b->calculatedVersion);
        $after = array_filter($pending, fn (AccountBalanceData $b) => (int) $b->account->id > (int) $afterAccountId);

        return new PendingBalancesData(array_values(array_map(fn (AccountBalanceData $b) => $b->account, array_slice($after, 0, 10))),
            count($pending), $pending === [] ? null : '2026-09-23T00:00:00.000000Z');
    }
}
