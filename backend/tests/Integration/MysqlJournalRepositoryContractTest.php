<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Accounting\Contracts\JournalRepository;
use Tests\Core\Contracts\JournalRepositoryContract;
use Tests\Core\Support\Accounts;
use Tests\Integration\Support\UsesMysql;

final class MysqlJournalRepositoryContractTest extends JournalRepositoryContract
{
    use UsesMysql;

    protected function repository(): JournalRepository
    {
        $this->persistAccounts(Accounts::data());

        return $this->app->make(JournalRepository::class);
    }
}
