<?php

declare(strict_types=1);

namespace Tests\Core\Contracts;

use App\Application\Auth\Contracts\TokenRevocationRepository;
use App\Application\Auth\Data\TokenClaimsData;
use PHPUnit\Framework\TestCase;

abstract class TokenRevocationRepositoryContract extends TestCase
{
    abstract protected function repository(): TokenRevocationRepository;

    public function test_revocation_is_scoped_to_the_exact_token(): void
    {
        $repository = $this->repository();
        $first = new TokenClaimsData('7', str_repeat('a', 64), 1000, 1900);
        $second = new TokenClaimsData('7', str_repeat('b', 64), 1000, 1900);
        self::assertFalse($repository->isRevoked($first->tokenId));
        $repository->revoke($first);
        self::assertTrue($repository->isRevoked($first->tokenId));
        self::assertFalse($repository->isRevoked($second->tokenId));
    }

    public function test_repeated_revocation_preserves_the_original_expiration(): void
    {
        $repository = $this->repository();
        $repository->revoke(new TokenClaimsData('7', str_repeat('a', 64), 1000, 1900));
        $repository->revoke(new TokenClaimsData('7', str_repeat('a', 64), 1000, 1500));
        self::assertSame(0, $repository->pruneExpired(1500));
        self::assertTrue($repository->isRevoked(str_repeat('a', 64)));
    }

    public function test_pruning_removes_only_tokens_at_or_after_their_expiration_boundary(): void
    {
        $repository = $this->repository();
        $repository->revoke(new TokenClaimsData('7', str_repeat('a', 64), 1000, 1899));
        $repository->revoke(new TokenClaimsData('7', str_repeat('b', 64), 1000, 1900));
        $repository->revoke(new TokenClaimsData('7', str_repeat('c', 64), 1001, 1901));
        self::assertSame(2, $repository->pruneExpired(1900));
        self::assertFalse($repository->isRevoked(str_repeat('a', 64)));
        self::assertFalse($repository->isRevoked(str_repeat('b', 64)));
        self::assertTrue($repository->isRevoked(str_repeat('c', 64)));
    }
}
