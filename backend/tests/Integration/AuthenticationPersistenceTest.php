<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Accounting\Data\PrepareCsvPostingRequest;
use App\Application\Accounting\PrepareCsvPostingUseCase;
use App\Application\Auth\AuthenticateTokenUseCase;
use App\Application\Auth\Contracts\PasswordHasher;
use App\Application\Auth\Contracts\TokenRevocationRepository;
use App\Application\Auth\Contracts\TokenService;
use App\Application\Auth\Data\AuthenticateRequest;
use App\Application\Auth\Data\TokenClaimsData;
use App\Application\Imports\Data\CsvRowData;
use App\Application\Shared\Contracts\Clock;
use App\Domain\Shared\DomainViolation;
use Illuminate\Foundation\Testing\Concerns\MakesHttpRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Tests\Core\Support\Accounts;
use Tests\Doubles\FrozenClock;
use Tests\Integration\Support\UsesMysql;

final class AuthenticationPersistenceTest extends TestCase
{
    use MakesHttpRequests, UsesMysql;

    public function test_real_login_reads_argon2id_hash_and_logout_persists_only_token_identifier(): void
    {
        $this->createUser();
        $hash = $this->app->make(PasswordHasher::class)->hash(' correct password ');
        DB::table('users')->where('id', 7)->update(['password' => $hash]);
        $response = $this->postJson('/api/v1/auth/login', ['email' => 'fixture-7@example.test', 'password' => ' correct password ']);
        $response->assertOk()->assertJsonPath('data.user.id', '7')->assertJsonMissingPath('data.user.password');
        $token = $response->json('data.access_token');
        $claims = $this->app->make(TokenService::class)->validate($token);
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', '7');
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $record = DB::table('revoked_tokens')->where('token_id', $claims->tokenId)->first();
        self::assertNotNull($record);
        self::assertSame(7, $record->user_id);
        self::assertSame($claims->expiresAt, $record->expires_at);
        self::assertStringNotContainsString($token, json_encode($record, JSON_THROW_ON_ERROR));
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_successful_login_rehashes_an_old_argon2id_cost_without_changing_the_password(): void
    {
        $this->createUser();
        $old = password_hash('password', PASSWORD_ARGON2ID, ['memory_cost' => 8192, 'time_cost' => 1, 'threads' => 1]);
        DB::table('users')->where('id', 7)->update(['password' => $old]);
        $this->postJson('/api/v1/auth/login', ['email' => 'fixture-7@example.test', 'password' => 'password'])->assertOk();
        $new = DB::table('users')->where('id', 7)->value('password');
        self::assertNotSame($old, $new);
        self::assertTrue(password_verify('password', $new));
        self::assertSame(['memory_cost' => 65536, 'time_cost' => 3, 'threads' => 1], password_get_info($new)['options']);
    }

    public function test_authenticated_owner_is_used_to_reject_a_foreign_csv_account(): void
    {
        $this->persistAccounts(Accounts::data());
        $token = $this->app->make(TokenService::class)->issue('7')->accessToken;
        $authentication = $this->app->make(AuthenticateTokenUseCase::class)->execute(new AuthenticateRequest($token));
        $this->expectException(DomainViolation::class);
        $this->expectExceptionMessage('another owner');
        $this->app->make(PrepareCsvPostingUseCase::class)->execute(new PrepareCsvPostingRequest(
            $authentication->user->id, new CsvRowData('2026-08-16', 'Compra #683', '494618', 'Despesa'),
        ));
    }

    public function test_token_of_a_deleted_user_cannot_access_the_api(): void
    {
        $this->createUser();
        $token = $this->app->make(TokenService::class)->issue('7')->accessToken;
        DB::table('users')->where('id', 7)->delete();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_pruning_command_keeps_unexpired_revocations(): void
    {
        $this->createUser();
        $clock = new FrozenClock;
        $this->app->instance(Clock::class, $clock);
        $revocations = $this->app->make(TokenRevocationRepository::class);
        $revocations->revoke(new TokenClaimsData('7', str_repeat('a', 64), $clock->now() - 900, $clock->now()));
        $revocations->revoke(new TokenClaimsData('7', str_repeat('b', 64), $clock->now(), $clock->now() + 900));
        self::assertSame(0, Artisan::call('auth:prune-revoked-tokens'));
        self::assertFalse($revocations->isRevoked(str_repeat('a', 64)));
        self::assertTrue($revocations->isRevoked(str_repeat('b', 64)));
    }
}
