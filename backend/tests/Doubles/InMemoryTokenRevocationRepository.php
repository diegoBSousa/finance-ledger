<?php

declare(strict_types=1);

namespace Tests\Doubles;

use App\Application\Auth\Contracts\TokenRevocationRepository;
use App\Application\Auth\Data\TokenClaimsData;

final class InMemoryTokenRevocationRepository implements TokenRevocationRepository
{
    /** @var array<string, TokenClaimsData> */
    public array $records = [];

    public function isRevoked(string $tokenId): bool
    {
        return isset($this->records[$tokenId]);
    }

    public function revoke(TokenClaimsData $token): void
    {
        $this->records[$token->tokenId] ??= $token;
    }

    public function pruneExpired(int $now): int
    {
        $before = count($this->records);
        $this->records = array_filter($this->records, fn (TokenClaimsData $token) => $token->expiresAt > $now);

        return $before - count($this->records);
    }
}
