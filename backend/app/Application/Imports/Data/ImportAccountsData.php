<?php

declare(strict_types=1);

namespace App\Application\Imports\Data;

use App\Domain\Accounting\Data\AccountData;

final readonly class ImportAccountsData
{
    /** @param list<AccountData> $accounts */
    public function __construct(public array $accounts) {}
}
