<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Application\Balances\AccountNotFound;
use App\Application\Balances\BalanceUnavailable;
use App\Application\Balances\Contracts\BalanceRepository;
use App\Application\Balances\Data\AccountBalanceData;
use App\Application\Balances\Data\GetAccountBalanceRequest;
use App\Application\Balances\Data\ListAccountBalancesRequest;
use App\Application\Balances\Data\ProjectBalancesRequest;
use App\Application\Balances\Data\RefreshAccountBalanceRequest;
use App\Application\Balances\Data\RefreshedBalanceData;
use App\Application\Balances\GetAccountBalanceUseCase;
use App\Application\Balances\ListAccountBalancesUseCase;
use App\Application\Balances\ProjectBalancesUseCase;
use App\Application\Balances\RefreshAccountBalanceUseCase;
use App\Application\Pagination\PageRequest;
use App\Domain\Accounting\Data\AccountData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Core\Support\Accounts;
use Tests\Core\Support\PostingBatches;
use Tests\Doubles\InMemoryBalanceRepository;

final class BalanceUseCasesTest extends TestCase
{
    public function test_listing_refreshes_only_the_authorized_page_and_keeps_other_accounts_pending(): void
    {
        $repository = new InMemoryBalanceRepository(Accounts::data(), [PostingBatches::entry()]);
        $list = new ListAccountBalancesUseCase($repository, new RefreshAccountBalanceUseCase($repository));
        $response = $list->execute(new ListAccountBalancesRequest('7', new PageRequest(1, 1)));
        self::assertSame(['42'], $repository->refreshed);
        self::assertSame('-494618', $response->balances[0]->balanceMinor);
        self::assertTrue($repository->balances['7002']->staled);
        self::assertSame(2, $response->total);
        self::assertSame('44', $list->execute(new ListAccountBalancesRequest('7', new PageRequest(2, 1)))->balances[0]->account->id);
    }

    public function test_detail_uses_external_number_and_hides_foreign_accounts(): void
    {
        $repository = new InMemoryBalanceRepository(Accounts::data());
        $get = new GetAccountBalanceUseCase(new ListAccountBalancesUseCase($repository, new RefreshAccountBalanceUseCase($repository)));
        self::assertSame('42', $get->execute(new GetAccountBalanceRequest('7', '682'))->balance->account->id);
        $this->expectException(AccountNotFound::class);
        $get->execute(new GetAccountBalanceRequest('7', '683'));
    }

    #[DataProvider('invalidResults')]
    public function test_use_case_rejects_an_adapter_returning_an_inconsistent_or_unauthorized_balance(?RefreshedBalanceData $result): void
    {
        $repository = $this->createStub(BalanceRepository::class);
        $repository->method('refresh')->willReturn($result);
        $this->expectException(BalanceUnavailable::class);
        (new RefreshAccountBalanceUseCase($repository))->execute(new RefreshAccountBalanceRequest('7', '42'));
    }

    public static function invalidResults(): iterable
    {
        $account = Accounts::data()[0];
        yield 'missing result' => [null];
        yield 'stale' => [new RefreshedBalanceData(new AccountBalanceData($account, '0', '0', '0', '1', '0', null, true), false)];
        yield 'versions differ' => [new RefreshedBalanceData(new AccountBalanceData($account, '0', '0', '0', '1', '0', null, false), false)];
        yield 'foreign' => [new RefreshedBalanceData(new AccountBalanceData(new AccountData('42', '8', '682', 'asset'), '0', '0', '0', '0', '0', null, false), false)];
    }

    public function test_projector_continues_past_failures_and_locks_then_retries_them_on_a_later_sweep(): void
    {
        $repository = new InMemoryBalanceRepository(Accounts::data(), [PostingBatches::entry(), PostingBatches::entry('Income #682', '5', 'Receita')]);
        $repository->failing = ['42'];
        $repository->locked = ['7001'];
        $project = new ProjectBalancesUseCase($repository, new RefreshAccountBalanceUseCase($repository));
        $first = $project->execute(new ProjectBalancesRequest);
        self::assertSame(3, $first->examined);
        self::assertSame(1, $first->failed);
        self::assertSame(1, $first->skipped);
        self::assertSame(1, $first->recalculated);
        self::assertSame('7002', $first->nextAccountId);
        $wrap = $project->execute(new ProjectBalancesRequest($first->nextAccountId));
        self::assertSame('0', $wrap->nextAccountId);
        self::assertSame(2, $wrap->pendingBefore);
        $repository->failing = $repository->locked = [];
        self::assertSame(2, $project->execute(new ProjectBalancesRequest)->recalculated);
        self::assertSame(0, $project->execute(new ProjectBalancesRequest)->examined);
    }

    public function test_query_failure_is_not_converted_into_an_empty_successful_projector_run(): void
    {
        $repository = $this->createStub(BalanceRepository::class);
        $repository->method('pending')->willThrowException(new BalanceUnavailable);
        $this->expectException(BalanceUnavailable::class);
        (new ProjectBalancesUseCase($repository, new RefreshAccountBalanceUseCase($repository)))->execute(new ProjectBalancesRequest);
    }
}
