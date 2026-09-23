<?php

declare(strict_types=1);

namespace Tests\Core\Contracts;

use App\Application\Auth\Contracts\UserRepository;
use Tests\Doubles\InMemoryUserRepository;

final class InMemoryUserRepositoryContractTest extends UserRepositoryContract
{
    protected function repositoryWith(array $users): UserRepository
    {
        return new InMemoryUserRepository($users);
    }
}
