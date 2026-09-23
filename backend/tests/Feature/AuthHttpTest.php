<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Auth\AuthenticationUnavailable;
use App\Application\Auth\Contracts\PasswordHasher;
use App\Application\Auth\Contracts\TokenRevocationRepository;
use App\Application\Auth\Contracts\TokenService;
use App\Application\Auth\Contracts\UserRepository;
use App\Application\Auth\Data\UserCredentialsData;
use App\Application\Auth\Data\UserData;
use App\Application\Shared\Contracts\Clock;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Doubles\FrozenClock;
use Tests\Doubles\InMemoryTokenRevocationRepository;
use Tests\Doubles\InMemoryUserRepository;
use Tests\TestCase;

final class AuthHttpTest extends TestCase
{
    private FrozenClock $clock;

    private static ?string $passwordHash = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FrozenClock;
        $this->app->instance(Clock::class, $this->clock);
        self::$passwordHash ??= $this->app->make(PasswordHasher::class)->hash(' exact password ');
        $this->app->instance(UserRepository::class, new InMemoryUserRepository([
            new UserCredentialsData(new UserData('7', 'First', 'first@example.test'), self::$passwordHash),
            new UserCredentialsData(new UserData('8', 'Second', 'second@example.test'), self::$passwordHash),
        ]));
        $this->app->instance(TokenRevocationRepository::class, new InMemoryTokenRevocationRepository);
    }

    public function test_login_me_and_logout_follow_the_http_contract(): void
    {
        $login = $this->postJson('/api/v1/auth/login', ['email' => ' FIRST@EXAMPLE.TEST ', 'password' => ' exact password ']);
        $login->assertOk()->assertJsonPath('data.token_type', 'Bearer')->assertJsonPath('data.expires_in', 900)
            ->assertJsonPath('data.user.id', '7')->assertJsonMissingPath('data.user.password')
            ->assertHeader('Pragma', 'no-cache');
        self::assertStringContainsString('no-store', $login->headers->get('Cache-Control'));
        self::assertSame([], $login->headers->getCookies());
        $token = $login->json('data.access_token');
        $this->withToken($token)->getJson('/api/v1/auth/me?user_id=8')
            ->assertOk()->assertExactJson(['data' => ['id' => '7', 'name' => 'First', 'email' => 'first@example.test']]);
        $this->withToken($token)->postJson('/api/v1/auth/logout', ['user_id' => '8'])
            ->assertOk()->assertExactJson(['data' => ['revoked' => true]]);
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertUnauthorized();
    }

    public function test_login_error_does_not_reveal_whether_the_email_exists(): void
    {
        $known = $this->postJson('/api/v1/auth/login', ['email' => 'first@example.test', 'password' => 'wrong']);
        $missing = $this->postJson('/api/v1/auth/login', ['email' => 'unknown@example.test', 'password' => 'wrong']);
        $known->assertUnauthorized()->assertJsonPath('code', 'invalid_credentials');
        $missing->assertUnauthorized();
        self::assertSame($known->json(), $missing->json());
    }

    #[DataProvider('invalidRequests')]
    public function test_invalid_login_input_returns_422_without_issuing_a_token(array $data): void
    {
        $this->postJson('/api/v1/auth/login', $data)->assertUnprocessable()->assertJsonMissingPath('data.access_token');
    }

    public static function invalidRequests(): iterable
    {
        yield 'missing credentials' => [[]];
        yield 'array email' => [['email' => ['first@example.test'], 'password' => 'password']];
        yield 'invalid email' => [['email' => 'invalid', 'password' => 'password']];
        yield 'array password' => [['email' => 'first@example.test', 'password' => ['password']]];
        yield 'null byte' => [['email' => 'first@example.test', 'password' => "a\0b"]];
        yield 'byte size limit' => [['email' => 'first@example.test', 'password' => str_repeat('é', 513)]];
    }

    #[DataProvider('invalidBearerHeaders')]
    public function test_protected_routes_require_a_single_valid_bearer_header(?string $header): void
    {
        if ($header !== null) {
            $this->withHeader('Authorization', $header);
        }
        $this->get('/api/v1/auth/me')->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer')
            ->assertHeader('Content-Type', 'application/json')->assertJsonPath('code', 'unauthenticated');
    }

    public static function invalidBearerHeaders(): iterable
    {
        yield 'missing' => [null];
        yield 'basic' => ['Basic abc'];
        yield 'prefix injection' => ['Something Bearer abc'];
        yield 'multiple credentials' => ['Bearer abc,Bearer def'];
        yield 'invalid token' => ['Bearer abc'];
    }

    public function test_query_string_and_cookies_cannot_supply_the_bearer_token(): void
    {
        $token = $this->app->make(TokenService::class)->issue('7')->accessToken;
        $this->withCookie('access_token', $token)->getJson('/api/v1/auth/me?access_token='.urlencode($token))->assertUnauthorized();
    }

    public function test_expired_token_is_rejected_by_the_api(): void
    {
        $token = $this->app->make(TokenService::class)->issue('7')->accessToken;
        $this->clock->timestamp += 900;
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_logging_out_one_token_preserves_other_sessions(): void
    {
        $tokens = $this->app->make(TokenService::class);
        $first = $tokens->issue('7')->accessToken;
        $second = $tokens->issue('7')->accessToken;
        $otherUser = $tokens->issue('8')->accessToken;
        $this->withToken($first)->postJson('/api/v1/auth/logout', ['token' => $second])->assertOk();
        $this->withToken($second)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', '7');
        $this->withToken($otherUser)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', '8');
    }

    public function test_sixth_login_attempt_for_same_email_and_ip_is_throttled(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'first@example.test', 'password' => 'wrong'])->assertUnauthorized();
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'FIRST@EXAMPLE.TEST', 'password' => ' exact password '])
            ->assertStatus(429)->assertHeader('Retry-After')->assertJsonPath('code', 'rate_limited');
    }

    public function test_rotating_emails_does_not_bypass_the_ip_limit(): void
    {
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'invalid-'.$attempt])->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'first@example.test', 'password' => ' exact password '])->assertStatus(429);
    }

    public function test_revocation_storage_failure_is_503_and_never_authenticates_the_request(): void
    {
        $token = $this->app->make(TokenService::class)->issue('7')->accessToken;
        $revocations = $this->createStub(TokenRevocationRepository::class);
        $revocations->method('isRevoked')->willThrowException(new AuthenticationUnavailable);
        $this->app->instance(TokenRevocationRepository::class, $revocations);
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(503)->assertJsonPath('code', 'authentication_unavailable');
    }

    public function test_logout_storage_failure_does_not_report_success(): void
    {
        $token = $this->app->make(TokenService::class)->issue('7')->accessToken;
        $revocations = $this->createStub(TokenRevocationRepository::class);
        $revocations->method('isRevoked')->willReturn(false);
        $revocations->method('revoke')->willThrowException(new AuthenticationUnavailable);
        $this->app->instance(TokenRevocationRepository::class, $revocations);
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertStatus(503)->assertJsonMissingPath('data.revoked');
    }
}
