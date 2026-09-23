<?php

declare(strict_types=1);

namespace App\Application\Imports;

use App\Application\Accounting\Contracts\AccountRepository;
use App\Application\Imports\Data\ImportAccountsData;
use App\Domain\Accounting\AccountKind;
use App\Domain\Accounting\Data\AccountData;
use App\Domain\Shared\DomainViolation;

/** A snapshot scoped to one preparation; persistence revalidates accounts under locks. */
final readonly class ResolvedImportAccounts implements AccountRepository
{
    /** @var array<string,AccountData> */
    private array $financial;

    /** @var array<string,AccountData> */
    private array $technical;

    public function __construct(ImportAccountsData $data)
    {
        $financial = $technical = [];
        foreach ($data->accounts as $account) {
            if ($account->kind === AccountKind::Asset->value && $account->externalNumber !== null) {
                $financial[$account->externalNumber] = $account;
            } else {
                $technical[$account->ownerUserId.':'.$account->kind] = $account;
            }
        }
        $this->financial = $financial;
        $this->technical = $technical;
    }

    public function findFinancialByNumber(string $externalNumber): ?AccountData
    {
        return $this->financial[$externalNumber] ?? null;
    }

    public function findTechnical(string $ownerUserId, AccountKind $kind): ?AccountData
    {
        if ($kind === AccountKind::Asset) {
            throw new DomainViolation('invalid_technical_kind', 'An asset is not a technical counterpart account.');
        }

        return $this->technical[$ownerUserId.':'.$kind->value] ?? null;
    }
}
