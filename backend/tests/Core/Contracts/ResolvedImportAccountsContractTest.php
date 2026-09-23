<?php

declare(strict_types=1);

namespace Tests\Core\Contracts;

use App\Application\Accounting\Contracts\AccountRepository;
use App\Application\Imports\Data\ImportAccountsData;
use App\Application\Imports\ResolvedImportAccounts;

final class ResolvedImportAccountsContractTest extends AccountRepositoryContract
{
    protected function repositoryWith(array $accounts): AccountRepository
    {
        return new ResolvedImportAccounts(new ImportAccountsData($accounts));
    }
}
