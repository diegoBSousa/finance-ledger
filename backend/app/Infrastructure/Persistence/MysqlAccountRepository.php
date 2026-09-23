<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Accounting\Contracts\AccountRepository;
use App\Domain\Accounting\AccountKind;
use App\Domain\Accounting\Data\AccountData;
use App\Domain\Shared\DomainViolation;
use App\Infrastructure\Persistence\Models\AccountRecord;

final class MysqlAccountRepository implements AccountRepository
{
    public function findFinancialByNumber(string $externalNumber): ?AccountData
    {
        return AccountRecord::query()
            ->where('external_number', $externalNumber)
            ->where('kind', AccountKind::Asset->value)
            ->first()?->toData();
    }

    public function findTechnical(string $ownerUserId, AccountKind $kind): ?AccountData
    {
        if ($kind === AccountKind::Asset) {
            throw new DomainViolation('invalid_technical_kind', 'An asset is not a technical counterpart account.');
        }

        return AccountRecord::query()
            ->where('owner_user_id', $ownerUserId)
            ->where('kind', $kind->value)
            ->first()?->toData();
    }
}
