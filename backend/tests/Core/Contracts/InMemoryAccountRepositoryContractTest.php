<?php

declare(strict_types=1);

namespace Tests\Core\Contracts;

use App\Application\Accounting\Contracts\AccountRepository;
use Tests\Doubles\InMemoryAccountRepository;

final class InMemoryAccountRepositoryContractTest extends AccountRepositoryContract
{
    protected function repositoryWith(array $accounts): AccountRepository
    {
        return new InMemoryAccountRepository($accounts);
    }
}
