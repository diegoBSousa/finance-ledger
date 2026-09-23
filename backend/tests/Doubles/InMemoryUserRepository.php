<?php

declare(strict_types=1);

namespace Tests\Doubles;

use App\Application\Auth\Contracts\UserRepository;
use App\Application\Auth\Data\UserCredentialsData;
use App\Application\Auth\Data\UserData;

final class InMemoryUserRepository implements UserRepository
{
    /** @param list<UserCredentialsData> $users */
    public function __construct(public array $users = []) {}

    public function findCredentialsByEmail(string $email): ?UserCredentialsData
    {
        foreach ($this->users as $credentials) {
            if (strtolower($credentials->user->email) === strtolower($email)) {
                return $credentials;
            }
        }

        return null;
    }

    public function findById(string $userId): ?UserData
    {
        foreach ($this->users as $credentials) {
            if ($credentials->user->id === $userId) {
                return $credentials->user;
            }
        }

        return null;
    }

    public function replacePasswordHash(string $userId, string $expectedHash, string $newHash): void
    {
        foreach ($this->users as $index => $credentials) {
            if ($credentials->user->id === $userId && $credentials->passwordHash === $expectedHash) {
                $this->users[$index] = new UserCredentialsData($credentials->user, $newHash);
            }
        }
    }
}
