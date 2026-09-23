<?php

declare(strict_types=1);

namespace Tests\Core\Contracts;

use App\Application\Auth\Contracts\UserRepository;
use App\Application\Auth\Data\UserCredentialsData;
use App\Application\Auth\Data\UserData;
use PHPUnit\Framework\TestCase;

abstract class UserRepositoryContract extends TestCase
{
    /** @param list<UserCredentialsData> $users */
    abstract protected function repositoryWith(array $users): UserRepository;

    private function repository(): UserRepository
    {
        return $this->repositoryWith([
            new UserCredentialsData(new UserData('7', 'First', 'first@example.test'), 'OriginalHash'),
            new UserCredentialsData(new UserData('8', 'Second', 'second@example.test'), 'OtherHash'),
        ]);
    }

    public function test_missing_identity_returns_null_without_creating_a_user(): void
    {
        $repository = $this->repository();
        self::assertNull($repository->findById('999'));
        self::assertNull($repository->findCredentialsByEmail('missing@example.test'));
    }

    public function test_profile_contains_only_public_data_and_string_id(): void
    {
        $data = $this->repository()->findById('7');
        self::assertInstanceOf(UserData::class, $data);
        self::assertSame(['id' => '7', 'name' => 'First', 'email' => 'first@example.test'], get_object_vars($data));
    }

    public function test_credentials_lookup_is_case_insensitive_for_ascii_emails(): void
    {
        $credentials = $this->repository()->findCredentialsByEmail('FIRST@example.test');
        self::assertSame('7', $credentials?->user->id);
        self::assertSame('OriginalHash', $credentials?->passwordHash);
    }

    public function test_rehash_compares_old_hash_exactly_and_never_overwrites_a_concurrent_password_change(): void
    {
        $repository = $this->repository();
        $repository->replacePasswordHash('7', 'originalhash', 'must-not-write');
        self::assertSame('OriginalHash', $repository->findCredentialsByEmail('first@example.test')?->passwordHash);
        $repository->replacePasswordHash('7', 'OriginalHash', 'UpdatedHash');
        $repository->replacePasswordHash('7', 'OriginalHash', 'stale-write');
        self::assertSame('UpdatedHash', $repository->findCredentialsByEmail('first@example.test')?->passwordHash);
        self::assertSame('OtherHash', $repository->findCredentialsByEmail('second@example.test')?->passwordHash);
    }
}
