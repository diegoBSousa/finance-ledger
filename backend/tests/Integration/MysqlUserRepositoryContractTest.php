<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Auth\Contracts\UserRepository;
use Illuminate\Support\Facades\DB;
use Tests\Core\Contracts\UserRepositoryContract;
use Tests\Integration\Support\UsesMysql;

final class MysqlUserRepositoryContractTest extends UserRepositoryContract
{
    use UsesMysql;

    protected function repositoryWith(array $users): UserRepository
    {
        foreach ($users as $credentials) {
            DB::table('users')->insert([
                'id' => $credentials->user->id, 'name' => $credentials->user->name,
                'email' => $credentials->user->email, 'password' => $credentials->passwordHash,
            ]);
        }

        return $this->app->make(UserRepository::class);
    }
}
