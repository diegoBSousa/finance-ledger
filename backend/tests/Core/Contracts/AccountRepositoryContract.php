<?php

declare(strict_types=1);

namespace Tests\Core\Contracts;

use App\Application\Accounting\Contracts\AccountRepository;
use App\Domain\Accounting\AccountKind;
use App\Domain\Accounting\Data\AccountData;
use App\Domain\Shared\DomainViolation;
use PHPUnit\Framework\TestCase;
use Tests\Core\Support\Accounts;

/** Shared expectations for the in-memory double and the real MySQL adapter. */
abstract class AccountRepositoryContract extends TestCase
{
    /** @param list<AccountData> $accounts */
    abstract protected function repositoryWith(array $accounts): AccountRepository;

    public function test_external_number_and_internal_id_are_distinct(): void
    {
        $repository = $this->repositoryWith(Accounts::data());
        self::assertSame('42', $repository->findFinancialByNumber('682')?->id);
        self::assertNull($repository->findFinancialByNumber('42'));
    }

    public function test_missing_accounts_return_null_without_creation(): void
    {
        $repository = $this->repositoryWith(Accounts::data());
        self::assertNull($repository->findFinancialByNumber('999'));
        self::assertNull($repository->findFinancialByNumber('999'));
        self::assertNull($repository->findTechnical('9', AccountKind::Expense));
    }

    public function test_technical_accounts_are_scoped_by_owner_and_kind(): void
    {
        $repository = $this->repositoryWith(Accounts::data());
        self::assertSame('7001', $repository->findTechnical('7', AccountKind::Revenue)?->id);
        self::assertSame('7002', $repository->findTechnical('7', AccountKind::Expense)?->id);
        self::assertSame('8002', $repository->findTechnical('8', AccountKind::Expense)?->id);
    }

    public function test_inactive_accounts_remain_visible_as_data_for_validation(): void
    {
        $data = $this->repositoryWith(Accounts::data())->findFinancialByNumber('684');
        self::assertInstanceOf(AccountData::class, $data);
        self::assertFalse($data->active);
    }

    public function test_assets_are_not_valid_technical_accounts(): void
    {
        $this->expectException(DomainViolation::class);
        $this->repositoryWith(Accounts::data())->findTechnical('7', AccountKind::Asset);
    }
}
