<?php

declare(strict_types=1);

namespace App\Application\Accounting\Contracts;

use App\Domain\Accounting\AccountKind;
use App\Domain\Accounting\Data\AccountData;

interface AccountRepository
{
    /**
     * Resolve a canonical external number. Missing accounts return null.
     * Inactive accounts are returned with active=false, not hidden or created.
     */
    public function findFinancialByNumber(string $externalNumber): ?AccountData;

    /**
     * Resolve the owner's revenue or expense account. Missing accounts return null.
     * Implementations must reject Asset as a technical kind and never create an account.
     */
    public function findTechnical(string $ownerUserId, AccountKind $kind): ?AccountData;
}
