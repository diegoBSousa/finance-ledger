<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Auth\Contracts\TokenRevocationRepository;
use App\Application\Auth\Contracts\TokenService;
use App\Application\Auth\Contracts\UserRepository;
use App\Application\Auth\Data\UserCredentialsData;
use App\Application\Auth\Data\UserData;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Doubles\InMemoryTokenRevocationRepository;
use Tests\Doubles\InMemoryUserRepository;
use Tests\TestCase;

final class FinancialReadHttpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(UserRepository::class, new InMemoryUserRepository([new UserCredentialsData(new UserData('7', 'First', 'first@example.test'), 'unused')]));
        $this->app->instance(TokenRevocationRepository::class, new InMemoryTokenRevocationRepository);
    }

    #[DataProvider('routes')]
    public function test_new_routes_require_jwt_and_preserve_no_store_on_errors(string $path): void
    {
        $response = $this->getJson('/api/v1'.$path)->assertUnauthorized();
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->withToken($this->app->make(TokenService::class)->issue('7')->accessToken);
        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->getJson('/api/v1'.$path)->assertUnauthorized();
    }

    public static function routes(): iterable
    {
        foreach (['/dashboard', '/accounts', '/transactions', '/accounts/682/transactions', '/imports/1/rows', '/imports/1/errors'] as $path) {
            yield [$path];
        }
    }

    #[DataProvider('invalidRequests')]
    public function test_invalid_page_and_filter_inputs_are_rejected_before_sql(string $path): void
    {
        $this->withToken($this->app->make(TokenService::class)->issue('7')->accessToken);
        $this->getJson('/api/v1'.$path)->assertUnprocessable();
    }

    public static function invalidRequests(): iterable
    {
        foreach (['/accounts', '/transactions', '/accounts/682/transactions', '/imports/1/rows', '/imports/1/errors'] as $path) {
            foreach (['per_page=11', 'per_page[]=10', 'per_page=1e1', 'page=9223372036854775807&per_page=10'] as $query) {
                yield [$path.'?'.$query];
            }
        }
        foreach (['date_from=2026-02-30', 'date_from=2026-08-17&date_to=2026-08-16', 'date_from[]=2026-08-16',
            'date_to=2026-8-1', 'type=Receita', 'type[]=income', 'account_number=9223372036854775808', 'account_number[]=682'] as $query) {
            yield ['/transactions?'.$query];
        }
        yield ['/imports/1/rows?status=unknown'];
        yield ['/imports/1/rows?status[]=inserted'];
        yield ['/imports/9223372036854775808/rows'];
        yield ['/accounts/9223372036854775808/transactions'];
    }
}
