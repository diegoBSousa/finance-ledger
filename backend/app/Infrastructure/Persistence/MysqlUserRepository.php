<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Auth\AuthenticationUnavailable;
use App\Application\Auth\Contracts\UserRepository;
use App\Application\Auth\Data\UserCredentialsData;
use App\Application\Auth\Data\UserData;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

final class MysqlUserRepository implements UserRepository
{
    public function findCredentialsByEmail(string $email): ?UserCredentialsData
    {
        try {
            return User::query()->where('email', $email)->first()?->toCredentialsData();
        } catch (QueryException) {
            throw new AuthenticationUnavailable;
        }
    }

    public function findById(string $userId): ?UserData
    {
        try {
            return User::query()->select(['id', 'name', 'email'])->find($userId)?->toData();
        } catch (QueryException) {
            throw new AuthenticationUnavailable;
        }
    }

    public function replacePasswordHash(string $userId, #[SensitiveParameter] string $expectedHash, #[SensitiveParameter] string $newHash): void
    {
        try {
            DB::table('users')->where('id', $userId)->whereRaw('BINARY password = ?', [$expectedHash])
                ->update(['password' => $newHash, 'updated_at' => now()]);
        } catch (QueryException) {
            throw new AuthenticationUnavailable;
        }
    }
}
