<?php

declare(strict_types=1);

namespace Tests\Core\Contracts;

use App\Application\Accounting\Contracts\JournalRepository;
use Tests\Core\Support\Accounts;
use Tests\Doubles\InMemoryJournalRepository;

final class InMemoryJournalRepositoryContractTest extends JournalRepositoryContract
{
    protected function repository(): JournalRepository
    {
        return new InMemoryJournalRepository(Accounts::data());
    }
}
