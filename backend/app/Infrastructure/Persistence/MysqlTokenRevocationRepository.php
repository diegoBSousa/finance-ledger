<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Auth\AuthenticationUnavailable;
use App\Application\Auth\Contracts\TokenRevocationRepository;
use App\Application\Auth\Data\TokenClaimsData;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class MysqlTokenRevocationRepository implements TokenRevocationRepository
{
    public function isRevoked(string $tokenId): bool
    {
        try {
            return DB::table('revoked_tokens')->where('token_id', $tokenId)->exists();
        } catch (QueryException) {
            throw new AuthenticationUnavailable;
        }
    }

    public function revoke(TokenClaimsData $token): void
    {
        try {
            DB::table('revoked_tokens')->upsert([
                'token_id' => $token->tokenId,
                'user_id' => $token->userId,
                'expires_at' => $token->expiresAt,
            ], ['token_id'], ['token_id']);
        } catch (QueryException) {
            throw new AuthenticationUnavailable;
        }
    }

    public function pruneExpired(int $now): int
    {
        try {
            return DB::table('revoked_tokens')->where('expires_at', '<=', $now)->delete();
        } catch (QueryException) {
            throw new AuthenticationUnavailable;
        }
    }
}
