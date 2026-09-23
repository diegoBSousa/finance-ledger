<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Application\Auth\AuthenticateTokenUseCase;
use App\Application\Auth\AuthenticationFailed;
use App\Application\Auth\AuthenticationUnavailable;
use App\Application\Auth\Contracts\PasswordHasher;
use App\Application\Auth\Contracts\TokenRevocationRepository;
use App\Application\Auth\Contracts\TokenService;
use App\Application\Auth\Contracts\UserRepository;
use App\Application\Auth\CurrentUserUseCase;
use App\Application\Auth\Data\AuthenticateRequest;
use App\Application\Auth\Data\AuthenticationData;
use App\Application\Auth\Data\CurrentUserRequest;
use App\Application\Auth\Data\IssuedTokenData;
use App\Application\Auth\Data\LoginRequest;
use App\Application\Auth\Data\LogoutRequest;
use App\Application\Auth\Data\TokenClaimsData;
use App\Application\Auth\Data\UserCredentialsData;
use App\Application\Auth\Data\UserData;
use App\Application\Auth\LoginUseCase;
use App\Application\Auth\LogoutUseCase;
use App\Domain\Shared\DomainViolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Doubles\InMemoryTokenRevocationRepository;
use Tests\Doubles\InMemoryUserRepository;

final class AuthenticationUseCasesTest extends TestCase
{
    public function test_login_normalizes_email_but_preserves_the_exact_password(): void
    {
        $request = new LoginRequest('  FIRST@EXAMPLE.TEST ', ' secret with spaces ');
        self::assertSame('first@example.test', $request->email);
        self::assertSame(' secret with spaces ', $request->password);
    }

    #[DataProvider('invalidLoginInputs')]
    public function test_login_dto_rejects_invalid_input(string $email, string $password): void
    {
        $this->expectException(DomainViolation::class);
        new LoginRequest($email, $password);
    }

    public static function invalidLoginInputs(): iterable
    {
        yield 'email missing' => ['', 'password'];
        yield 'invalid email' => ['not-an-email', 'password'];
        yield 'email too long' => [str_repeat('a', 255).'@example.test', 'password'];
        yield 'password missing' => ['first@example.test', ''];
        yield 'null byte' => ['first@example.test', "pass\0word"];
        yield 'password over byte limit' => ['first@example.test', str_repeat('é', 513)];
    }

    public function test_successful_login_rehashes_if_needed_and_returns_only_public_profile_and_token(): void
    {
        $users = $this->users();
        $passwords = $this->createMock(PasswordHasher::class);
        $passwords->expects(self::once())->method('verify')->with('password', 'old-hash')->willReturn(true);
        $passwords->method('needsRehash')->willReturn(true);
        $passwords->expects(self::once())->method('hash')->with('password')->willReturn('new-hash');
        $tokens = $this->createMock(TokenService::class);
        $tokens->expects(self::once())->method('issue')->with('7')->willReturn(new IssuedTokenData('signed-token', 900));

        $response = (new LoginUseCase($users, $passwords, $tokens))->execute(new LoginRequest('first@example.test', 'password'));
        self::assertSame('new-hash', $users->findCredentialsByEmail('first@example.test')?->passwordHash);
        self::assertSame('7', $response->user->id);
        self::assertSame('signed-token', $response->token->accessToken);
        self::assertStringNotContainsString('password', json_encode($response, JSON_THROW_ON_ERROR));
    }

    #[DataProvider('rejectedCredentials')]
    public function test_unknown_user_and_wrong_password_use_the_same_failure(bool $known): void
    {
        $passwords = $this->createMock(PasswordHasher::class);
        $passwords->expects(self::once())->method('verify')->with('wrong', $known ? 'old-hash' : null)->willReturn(false);
        $tokens = $this->createMock(TokenService::class);
        $tokens->expects(self::never())->method('issue');

        try {
            (new LoginUseCase($known ? $this->users() : new InMemoryUserRepository, $passwords, $tokens))
                ->execute(new LoginRequest('first@example.test', 'wrong'));
            self::fail('Invalid credentials were accepted.');
        } catch (AuthenticationFailed $exception) {
            self::assertSame('invalid_credentials', $exception->reason);
            self::assertSame('Invalid email or password.', $exception->getMessage());
        }
    }

    public static function rejectedCredentials(): iterable
    {
        yield 'unknown user still verifies a dummy hash' => [false];
        yield 'wrong password' => [true];
    }

    public function test_valid_password_hash_is_not_replaced_unnecessarily(): void
    {
        $passwords = $this->createMock(PasswordHasher::class);
        $passwords->method('verify')->willReturn(true);
        $passwords->method('needsRehash')->willReturn(false);
        $passwords->expects(self::never())->method('hash');
        $tokens = $this->createStub(TokenService::class);
        $tokens->method('issue')->willReturn(new IssuedTokenData('signed-token', 900));
        $users = $this->users();
        (new LoginUseCase($users, $passwords, $tokens))->execute(new LoginRequest('first@example.test', 'password'));
        self::assertSame('old-hash', $users->findCredentialsByEmail('first@example.test')?->passwordHash);
    }

    public function test_invalid_signature_stops_before_any_revocation_or_user_lookup(): void
    {
        $tokens = $this->createStub(TokenService::class);
        $tokens->method('validate')->willThrowException(AuthenticationFailed::token());
        $revocations = $this->createMock(TokenRevocationRepository::class);
        $revocations->expects(self::never())->method('isRevoked');
        $users = $this->createMock(UserRepository::class);
        $users->expects(self::never())->method('findById');
        $this->expectException(AuthenticationFailed::class);
        (new AuthenticateTokenUseCase($tokens, $revocations, $users))->execute(new AuthenticateRequest('invalid'));
    }

    public function test_revoked_tokens_cannot_authenticate(): void
    {
        $revocations = new InMemoryTokenRevocationRepository;
        $revocations->revoke($this->claims());
        $this->expectException(AuthenticationFailed::class);
        (new AuthenticateTokenUseCase($this->validTokenService(), $revocations, $this->users()))->execute(new AuthenticateRequest('token'));
    }

    public function test_token_does_not_authenticate_a_deleted_user(): void
    {
        $this->expectException(AuthenticationFailed::class);
        (new AuthenticateTokenUseCase($this->validTokenService(), new InMemoryTokenRevocationRepository, new InMemoryUserRepository))
            ->execute(new AuthenticateRequest('token'));
    }

    public function test_revocation_storage_failure_never_allows_access(): void
    {
        $revocations = $this->createStub(TokenRevocationRepository::class);
        $revocations->method('isRevoked')->willThrowException(new AuthenticationUnavailable);
        $this->expectException(AuthenticationUnavailable::class);
        (new AuthenticateTokenUseCase($this->validTokenService(), $revocations, $this->users()))->execute(new AuthenticateRequest('token'));
    }

    public function test_current_user_and_logout_use_only_authenticated_context(): void
    {
        $revocations = new InMemoryTokenRevocationRepository;
        $context = (new AuthenticateTokenUseCase($this->validTokenService(), $revocations, $this->users()))
            ->execute(new AuthenticateRequest('token'));
        self::assertInstanceOf(AuthenticationData::class, $context);
        self::assertSame('7', (new CurrentUserUseCase)->execute(new CurrentUserRequest($context))->user->id);
        self::assertTrue((new LogoutUseCase($revocations))->execute(new LogoutRequest($context))->revoked);
        self::assertSame(['a'.str_repeat('a', 63)], array_keys($revocations->records));
    }

    public function test_logout_reports_failure_if_revocation_was_not_persisted(): void
    {
        $revocations = $this->createStub(TokenRevocationRepository::class);
        $revocations->method('revoke')->willThrowException(new AuthenticationUnavailable);
        $context = new AuthenticationData(new UserData('7', 'First', 'first@example.test'), $this->claims());
        $this->expectException(AuthenticationUnavailable::class);
        (new LogoutUseCase($revocations))->execute(new LogoutRequest($context));
    }

    private function users(): InMemoryUserRepository
    {
        return new InMemoryUserRepository([new UserCredentialsData(new UserData('7', 'First', 'first@example.test'), 'old-hash')]);
    }

    private function claims(): TokenClaimsData
    {
        return new TokenClaimsData('7', str_repeat('a', 64), 1000, 1900);
    }

    private function validTokenService(): TokenService
    {
        $tokens = $this->createStub(TokenService::class);
        $tokens->method('validate')->willReturn($this->claims());

        return $tokens;
    }
}
