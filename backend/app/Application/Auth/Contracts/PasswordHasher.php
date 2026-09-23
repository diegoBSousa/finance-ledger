<?php

declare(strict_types=1);

namespace App\Application\Auth\Contracts;

interface PasswordHasher
{
    public function hash(string $password): string;

    /** Null means an unknown user: perform a dummy verification, then return false. */
    public function verify(string $password, ?string $hash): bool;

    public function needsRehash(string $hash): bool;
}
