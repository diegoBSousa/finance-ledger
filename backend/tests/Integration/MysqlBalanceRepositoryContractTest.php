<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Accounting\Contracts\JournalRepository;
use App\Application\Accounting\Data\PostingBatchData;
use App\Application\Balances\Contracts\BalanceRepository;
use Tests\Core\Contracts\BalanceRepositoryContract;
use Tests\Integration\Support\UsesCommittedMysql;

final class MysqlBalanceRepositoryContractTest extends BalanceRepositoryContract
{
    use UsesCommittedMysql;

    protected function repositoryWith(array $accounts, array $entries = []): BalanceRepository
    {
        $this->persistAccounts($accounts);
        if ($entries !== []) {
            $this->app->make(JournalRepository::class)->post(new PostingBatchData('7', $entries));
        }
        $this->commitFixtures();

        return $this->app->make(BalanceRepository::class);
    }
}
