<?php

declare(strict_types=1);

namespace Tests\Core\Contracts;

use App\Application\Accounting\Data\PostJournalData;
use App\Application\Balances\AccountNotFound;
use App\Application\Balances\BalanceUnavailable;
use App\Application\Balances\Contracts\BalanceRepository;
use App\Application\Balances\Data\BalancePageQueryData;
use App\Application\Balances\Data\RefreshBalanceData;
use App\Application\Pagination\PageRequest;
use App\Domain\Accounting\Data\AccountData;
use PHPUnit\Framework\TestCase;
use Tests\Core\Support\Accounts;
use Tests\Core\Support\PostingBatches;

abstract class BalanceRepositoryContract extends TestCase
{
    /** @param list<AccountData> $accounts @param list<PostJournalData> $entries */
    abstract protected function repositoryWith(array $accounts, array $entries = []): BalanceRepository;

    public function test_page_is_owner_scoped_ordered_capped_and_keeps_inactive_history(): void
    {
        $accounts = Accounts::data();
        for ($i = 50; $i < 62; $i++) {
            $accounts[] = new AccountData((string) $i, '7', (string) (900 + $i), 'asset');
        }
        $repo = $this->repositoryWith(array_reverse($accounts));
        $first = $repo->page(new BalancePageQueryData('7', new PageRequest));
        self::assertCount(10, $first->accounts);
        self::assertSame(14, $first->total);
        self::assertSame('42', $first->accounts[0]->id);
        self::assertSame('44', $first->accounts[1]->id);
        self::assertFalse($first->accounts[1]->active);
        $second = $repo->page(new BalancePageQueryData('7', new PageRequest(2)));
        self::assertSame(['58', '59', '60', '61'], array_column($second->accounts, 'id'));
        self::assertSame([], $repo->page(new BalancePageQueryData('7', new PageRequest(3)))->accounts);
    }

    public function test_filter_uses_external_number_and_never_exposes_other_owners(): void
    {
        $repo = $this->repositoryWith(Accounts::data());
        self::assertSame('42', $repo->page(new BalancePageQueryData('7', new PageRequest, '682'))->accounts[0]->id);
        foreach (['42', '683', '999'] as $number) {
            self::assertSame(0, $repo->page(new BalancePageQueryData('7', new PageRequest, $number))->total);
        }
    }

    public function test_fresh_zero_balance_is_reused_without_changing_its_timestamp(): void
    {
        $repo = $this->repositoryWith(Accounts::data());
        $first = $repo->refresh(new RefreshBalanceData('7', '42'));
        $again = $repo->refresh(new RefreshBalanceData('7', '42'));
        self::assertFalse($first->recalculated);
        self::assertEquals($first, $again);
        self::assertSame('0', $first->balance->balanceMinor);
        self::assertSame([], $repo->pending('0')->accounts);
    }

    public function test_refresh_returns_exact_negative_asset_balance_and_only_clears_that_account(): void
    {
        $repo = $this->repositoryWith(Accounts::data(), [PostingBatches::entry(), PostingBatches::entry('Income #682', '20000', 'Receita')]);
        $result = $repo->refresh(new RefreshBalanceData('7', '42'));
        self::assertTrue($result->recalculated);
        self::assertSame('20000', $result->balance->debitTotalMinor);
        self::assertSame('494618', $result->balance->creditTotalMinor);
        self::assertSame('-474618', $result->balance->balanceMinor);
        self::assertSame('2', $result->balance->ledgerVersion);
        self::assertSame('2', $result->balance->calculatedVersion);
        self::assertFalse($result->balance->staled);
        self::assertNotNull($result->balance->calculatedAt);
        self::assertSame(['7001', '7002'], array_column($repo->pending('0')->accounts, 'id'));
        self::assertSame(['7002'], array_column($repo->pending('7001')->accounts, 'id'));
        self::assertFalse($repo->refresh(new RefreshBalanceData('7', '42'))->recalculated);
    }

    public function test_technical_balances_follow_their_normal_debit_or_credit_side(): void
    {
        $repo = $this->repositoryWith(Accounts::data(), [PostingBatches::entry(), PostingBatches::entry('Income #682', '20000', 'Receita')]);
        self::assertSame('20000', $repo->refresh(new RefreshBalanceData('7', '7001'))->balance->balanceMinor);
        self::assertSame('494618', $repo->refresh(new RefreshBalanceData('7', '7002'))->balance->balanceMinor);
    }

    public function test_refresh_rejects_a_foreign_account_before_exposing_its_balance(): void
    {
        $repo = $this->repositoryWith(Accounts::data(), [PostingBatches::entry()]);
        $this->expectException(AccountNotFound::class);
        $repo->refresh(new RefreshBalanceData('8', '42'));
    }

    public function test_refresh_rejects_a_missing_account_without_inventing_a_zero(): void
    {
        $repo = $this->repositoryWith(Accounts::data());
        $this->expectException(AccountNotFound::class);
        $repo->refresh(new RefreshBalanceData('7', '999'));
    }

    public function test_exact_signed_64_bit_amount_is_preserved_as_a_string(): void
    {
        $repo = $this->repositoryWith(Accounts::data(), [PostingBatches::entry('Large #682', (string) PHP_INT_MAX, 'Receita')]);
        self::assertSame((string) PHP_INT_MAX, $repo->refresh(new RefreshBalanceData('7', '42'))->balance->balanceMinor);
    }

    public function test_aggregate_overflow_fails_and_keeps_pending_work(): void
    {
        $repo = $this->repositoryWith(Accounts::data(), [
            PostingBatches::entry('Large #682', (string) PHP_INT_MAX, 'Receita'),
            PostingBatches::entry('Extra #682', '1', 'Receita'),
        ]);
        try {
            $repo->refresh(new RefreshBalanceData('7', '42'));
            self::fail('An overflowing sum must not be converted to a float or a zero balance.');
        } catch (BalanceUnavailable) {
            self::assertSame(['42', '7001'], array_column($repo->pending('0')->accounts, 'id'));
        }
    }
}
