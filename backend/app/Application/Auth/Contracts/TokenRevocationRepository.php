<?php

declare(strict_types=1);

namespace App\Application\Auth\Contracts;

use App\Application\Auth\Data\TokenClaimsData;

interface TokenRevocationRepository
{
    public function isRevoked(string $tokenId): bool;

    /** Durable and idempotent: repeated revocations preserve the original record. */
    public function revoke(TokenClaimsData $token): void;

    public function pruneExpired(int $now): int;
}
