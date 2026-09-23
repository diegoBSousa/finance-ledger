<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Accounting\Contracts\AccountRepository;
use App\Domain\Accounting\Data\AccountData;
use Tests\Core\Contracts\AccountRepositoryContract;
use Tests\Integration\Support\UsesMysql;

final class MysqlAccountRepositoryContractTest extends AccountRepositoryContract
{
    use UsesMysql;

    /** @param list<AccountData> $accounts */
    protected function repositoryWith(array $accounts): AccountRepository
    {
        $this->persistAccounts($accounts);

        return $this->app->make(AccountRepository::class);
    }
}
