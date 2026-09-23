<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Accounting\Contracts\JournalRepository;
use App\Application\Auth\Contracts\TokenService;
use Illuminate\Foundation\Testing\Concerns\MakesHttpRequests;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Tests\Core\Support\Accounts;
use Tests\Core\Support\PostingBatches;
use Tests\Integration\Support\UsesCommittedMysql;

final class BalanceApiMysqlTest extends TestCase
{
    use MakesHttpRequests;
    use UsesCommittedMysql;

    private function prepare(): void
    {
        $this->persistAccounts(Accounts::data());
        $this->app->make(JournalRepository::class)->post(PostingBatches::batch());
        $this->commitFixtures();
        $this->withToken($this->app->make(TokenService::class)->issue('7')->accessToken);
    }

    public function test_get_682_recalculates_in_mysql_with_no_projector_or_queue_running(): void
    {
        $this->prepare();
        self::assertSame(1, DB::table('account_balances')->where('account_id', 42)->value('staled'));
        $this->getJson('/api/v1/accounts/682/balance')->assertOk()
            ->assertJsonPath('data.account_id', '42')->assertJsonPath('data.account_number', '682')
            ->assertJsonPath('data.debit_total_minor', '0')->assertJsonPath('data.credit_total_minor', '494618')
            ->assertJsonPath('data.balance_minor', '-494618')->assertJsonPath('data.calculated_version', '1');
        $projection = DB::table('account_balances')->where('account_id', 42)->first();
        self::assertSame(0, $projection->staled);
        self::assertSame($projection->ledger_version, $projection->calculated_version);
        self::assertSame(1, DB::table('account_balances')->where('account_id', 7002)->value('staled'));
        $this->getJson('/api/v1/balances?per_page=1&page=2')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.account_id', '44')->assertJsonPath('meta.total', 2);
    }

    public function test_foreign_accounts_and_missing_projections_cannot_return_a_successful_balance(): void
    {
        $this->prepare();
        $this->getJson('/api/v1/accounts/683/balance')->assertNotFound()->assertJsonPath('code', 'account_not_found');
        DB::table('account_balances')->where('account_id', 42)->delete();
        $this->getJson('/api/v1/balances')->assertStatus(503)->assertJsonPath('code', 'balance_unavailable')->assertJsonMissingPath('data');
        self::assertFalse(DB::table('account_balances')->where('account_id', 42)->exists());
    }
}
