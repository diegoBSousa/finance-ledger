<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Auth\Contracts\TokenRevocationRepository;
use App\Application\Auth\Contracts\TokenService;
use App\Application\Auth\Contracts\UserRepository;
use App\Application\Auth\Data\UserCredentialsData;
use App\Application\Auth\Data\UserData;
use App\Application\Balances\Contracts\BalanceRepository;
use App\Domain\Accounting\Data\AccountData;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Core\Support\Accounts;
use Tests\Core\Support\PostingBatches;
use Tests\Doubles\InMemoryBalanceRepository;
use Tests\Doubles\InMemoryTokenRevocationRepository;
use Tests\Doubles\InMemoryUserRepository;
use Tests\TestCase;

final class BalanceHttpTest extends TestCase
{
    private InMemoryBalanceRepository $balances;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(UserRepository::class, new InMemoryUserRepository([
            new UserCredentialsData(new UserData('7', 'First', 'first@example.test'), 'unused'),
            new UserCredentialsData(new UserData('8', 'Second', 'second@example.test'), 'unused'),
        ]));
        $this->app->instance(TokenRevocationRepository::class, new InMemoryTokenRevocationRepository);
        $accounts = Accounts::data();
        for ($i = 50; $i < 61; $i++) {
            $accounts[] = new AccountData((string) $i, '7', (string) (900 + $i), 'asset');
        }
        $this->balances = new InMemoryBalanceRepository($accounts, [PostingBatches::entry('Large #682', (string) PHP_INT_MAX, 'Receita')]);
        $this->app->instance(BalanceRepository::class, $this->balances);
    }

    private function authenticate(): void
    {
        $this->withToken($this->app->make(TokenService::class)->issue('7')->accessToken);
    }

    public function test_both_routes_require_jwt_before_any_balance_is_read(): void
    {
        $this->getJson('/api/v1/balances')->assertUnauthorized();
        $this->getJson('/api/v1/accounts/682/balance')->assertUnauthorized();
        self::assertSame([], $this->balances->refreshed);
    }

    public function test_paginated_api_caps_results_at_ten_and_ignores_injected_owner(): void
    {
        $this->authenticate();
        $first = $this->getJson('/api/v1/balances?owner_user_id=8&actorUserId=8');
        $first->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('meta', [
            'current_page' => 1, 'per_page' => 10, 'total' => 13, 'last_page' => 2, 'has_next_page' => true,
        ])->assertJsonPath('data.0.account_id', '42')->assertJsonPath('data.0.account_number', '682')
            ->assertJsonPath('data.0.balance_minor', (string) PHP_INT_MAX)->assertJsonPath('data.0.currency', 'BRL')
            ->assertJsonPath('data.0.calculated_version', '1')->assertJsonMissingPath('data.0.staled')->assertJsonMissingPath('data.0.owner_user_id');
        self::assertStringContainsString('no-store', $first->headers->get('Cache-Control'));
        self::assertCount(10, $this->balances->refreshed);
        $this->getJson('/api/v1/balances?page=2')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('meta.has_next_page', false);
        $this->getJson('/api/v1/balances?page=3')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 13);
    }

    public function test_filter_and_detail_use_external_account_number_and_keep_inactive_history_visible(): void
    {
        $this->authenticate();
        $this->getJson('/api/v1/balances?account_number=682&per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/accounts/682/balance')->assertOk()->assertJsonPath('data.balance_minor', (string) PHP_INT_MAX);
        $this->getJson('/api/v1/accounts/684/balance')->assertOk()->assertJsonPath('data.active', false)->assertJsonPath('data.balance_minor', '0');
        $foreign = $this->getJson('/api/v1/accounts/683/balance')->assertNotFound()->assertJsonPath('code', 'account_not_found');
        $missing = $this->getJson('/api/v1/accounts/999/balance')->assertNotFound();
        self::assertSame($foreign->json(), $missing->json());
        $this->getJson('/api/v1/accounts/42/balance')->assertNotFound();
        $this->getJson('/api/v1/accounts/9223372036854775808/balance')->assertNotFound();
        $this->getJson('/api/v1/balances?account_number=683')->assertOk()->assertJsonCount(0, 'data');
    }

    #[DataProvider('invalidQueries')]
    public function test_invalid_pagination_and_filters_are_422_without_querying_balances(string $query): void
    {
        $this->authenticate();
        $response = $this->getJson('/api/v1/balances?'.$query)->assertUnprocessable();
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        self::assertSame([], $this->balances->refreshed);
    }

    public static function invalidQueries(): iterable
    {
        foreach (['per_page=11', 'per_page=0', 'per_page=-1', 'per_page=1.5', 'per_page=1e1', 'per_page[]=10', 'per_page=',
            'page=0', 'page=-1', 'page=1.1', 'page[]=1', 'page=9223372036854775808', 'page=9223372036854775807&per_page=10',
            'account_number=0', 'account_number[]=682', 'account_number=9223372036854775808', 'account_number=682x'] as $query) {
            yield $query => [$query];
        }
    }

    public function test_failure_returns_503_without_partial_old_or_zero_balances(): void
    {
        $this->authenticate();
        $this->balances->failing = ['44'];
        $response = $this->getJson('/api/v1/balances')->assertStatus(503)->assertHeader('Retry-After', '1')
            ->assertExactJson(['message' => 'Account balances are temporarily unavailable.', 'code' => 'balance_unavailable']);
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->balances->failing = ['42'];
        $this->getJson('/api/v1/accounts/682/balance')->assertStatus(503)->assertJsonMissingPath('data');
    }

    public function test_revoked_token_cannot_query_balances(): void
    {
        $this->authenticate();
        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->getJson('/api/v1/balances')->assertUnauthorized();
        self::assertSame([], $this->balances->refreshed);
    }

    public function test_projector_command_processes_without_http_or_redis_and_reports_failures(): void
    {
        $this->balances->failing = ['42'];
        $this->artisan('balances:project', ['--once' => true])->assertFailed();
        self::assertFalse($this->balances->balances['7001']->staled);
        self::assertTrue($this->balances->balances['42']->staled);
        $this->balances->failing = [];
        $this->artisan('balances:project', ['--once' => true])->assertSuccessful();
        self::assertFalse($this->balances->balances['42']->staled);
    }
}
