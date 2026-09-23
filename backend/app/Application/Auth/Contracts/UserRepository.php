<?php

declare(strict_types=1);

namespace App\Application\Auth\Contracts;

use App\Application\Auth\Data\UserCredentialsData;
use App\Application\Auth\Data\UserData;

interface UserRepository
{
    public function findCredentialsByEmail(string $email): ?UserCredentialsData;

    public function findById(string $userId): ?UserData;

    /** Compare-and-set: do not overwrite a password changed concurrently. */
    public function replacePasswordHash(string $userId, string $expectedHash, string $newHash): void;
}
