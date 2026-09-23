<?php

declare(strict_types=1);

namespace Tests\Core\Contracts;

use App\Application\Balances\Contracts\BalanceRepository;
use Tests\Doubles\InMemoryBalanceRepository;

final class InMemoryBalanceRepositoryContractTest extends BalanceRepositoryContract
{
    protected function repositoryWith(array $accounts, array $entries = []): BalanceRepository
    {
        return new InMemoryBalanceRepository($accounts, $entries);
    }
}
