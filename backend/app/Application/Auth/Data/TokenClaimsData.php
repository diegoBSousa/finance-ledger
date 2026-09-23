<?php

declare(strict_types=1);

namespace App\Application\Auth\Data;

final readonly class TokenClaimsData
{
    public function __construct(
        public string $userId,
        public string $tokenId,
        public int $issuedAt,
        public int $expiresAt,
    ) {}
}
