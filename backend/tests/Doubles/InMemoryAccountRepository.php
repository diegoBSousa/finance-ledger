<?php

declare(strict_types=1);

namespace Tests\Doubles;

use App\Application\Accounting\Contracts\AccountRepository;
use App\Domain\Accounting\AccountKind;
use App\Domain\Accounting\Data\AccountData;
use App\Domain\Shared\DomainViolation;

/** Test double only. This is not a production persistence adapter. */
final class InMemoryAccountRepository implements AccountRepository
{
    /** @var list<string> */
    public array $lookups = [];

    /** @param list<AccountData> $accounts */
    public function __construct(private readonly array $accounts) {}

    public function findFinancialByNumber(string $externalNumber): ?AccountData
    {
        $this->lookups[] = 'financial:'.$externalNumber;

        foreach ($this->accounts as $account) {
            if ($account->kind === 'asset' && $account->externalNumber === $externalNumber) {
                return $account;
            }
        }

        return null;
    }

    public function findTechnical(string $ownerUserId, AccountKind $kind): ?AccountData
    {
        if ($kind === AccountKind::Asset) {
            throw new DomainViolation('invalid_technical_kind', 'Technical accounts must be revenue or expense accounts.');
        }

        $this->lookups[] = 'technical:'.$ownerUserId.':'.$kind->value;

        foreach ($this->accounts as $account) {
            if ($account->ownerUserId === $ownerUserId && $account->kind === $kind->value && $account->externalNumber === null) {
                return $account;
            }
        }

        return null;
    }
}
