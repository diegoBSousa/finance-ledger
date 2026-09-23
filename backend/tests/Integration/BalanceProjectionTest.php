<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Accounting\Contracts\JournalRepository;
use App\Application\Accounting\Data\PostingBatchData;
use App\Application\Balances\BalanceUnavailable;
use App\Application\Balances\Contracts\BalanceRepository;
use App\Application\Balances\Data\RefreshBalanceData;
use App\Infrastructure\Persistence\MysqlBalanceRepository;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDOException;
use PHPUnit\Framework\TestCase;
use Tests\Core\Support\Accounts;
use Tests\Core\Support\PostingBatches;
use Tests\Integration\Support\UsesCommittedMysql;

final class BalanceProjectionTest extends TestCase
{
    use UsesCommittedMysql;

    private function prepare(): BalanceRepository
    {
        $this->persistAccounts(Accounts::data());
        $this->app->make(JournalRepository::class)->post(PostingBatches::batch());
        $this->commitFixtures();

        return $this->app->make(BalanceRepository::class);
    }

    public function test_refresh_uses_its_own_read_committed_session_despite_an_older_ambient_snapshot(): void
    {
        $repository = $this->prepare();
        DB::beginTransaction();
        self::assertSame(1, DB::table('journal_entries')->count());
        $this->app->make('config')->set('database.connections.writer_fixture', config('database.connections.mysql'));
        $default = DB::getDefaultConnection();
        try {
            DB::setDefaultConnection('writer_fixture');
            $this->app->make(JournalRepository::class)->post(new PostingBatchData('7', [PostingBatches::entry('Later #682', '100', 'Receita')]));
        } finally {
            DB::setDefaultConnection($default);
        }
        self::assertSame(1, DB::table('journal_entries')->count(), 'The ambient repeatable-read snapshot remains old.');
        $refreshed = $repository->refresh(new RefreshBalanceData('7', '42'));
        self::assertSame('-494518', $refreshed->balance->balanceMinor);
        self::assertSame('2', $refreshed->balance->calculatedVersion);
        $connection = DB::connection(MysqlBalanceRepository::CONNECTION);
        self::assertSame('READ-COMMITTED', $connection->selectOne('SELECT @@transaction_isolation AS level')->level);
        self::assertSame(0, $connection->transactionLevel());
        self::assertSame(1, DB::transactionLevel());
        DB::rollBack();
        self::assertSame(-494518, DB::table('account_balances')->where('account_id', 42)->value('balance_minor'));
    }

    public function test_refresh_failure_after_update_rolls_back_projection_and_preserves_staleness(): void
    {
        $repository = $this->prepare();
        $before = DB::table('account_balances')->where('account_id', 42)->first();
        $fail = true;
        DB::listen(function (QueryExecuted $query) use (&$fail): void {
            if ($fail && $query->connectionName === MysqlBalanceRepository::CONNECTION && str_starts_with($query->sql, 'update `account_balances`')) {
                $fail = false;
                throw new PDOException('Injected post-update failure');
            }
        });
        try {
            $repository->refresh(new RefreshBalanceData('7', '42'));
            self::fail('The injected failure must abort the refresh.');
        } catch (BalanceUnavailable) {
            self::assertEquals($before, DB::table('account_balances')->where('account_id', 42)->first());
            self::assertSame(0, DB::connection(MysqlBalanceRepository::CONNECTION)->transactionLevel());
        }
        self::assertTrue($repository->refresh(new RefreshBalanceData('7', '42'))->recalculated);
    }

    public function test_missing_projection_fails_without_creating_zero_even_for_background_refresh(): void
    {
        $repository = $this->prepare();
        DB::table('account_balances')->where('account_id', 42)->delete();
        foreach ([false, true] as $skipLocked) {
            try {
                $repository->refresh(new RefreshBalanceData('7', '42', $skipLocked));
                self::fail('A missing projection is an integrity failure.');
            } catch (BalanceUnavailable) {
                self::assertFalse(DB::table('account_balances')->where('account_id', 42)->exists());
            }
        }
    }

    public function test_ambient_transaction_on_the_dedicated_connection_is_rejected(): void
    {
        $repository = $this->prepare();
        DB::connection(MysqlBalanceRepository::CONNECTION)->beginTransaction();
        $this->expectException(BalanceUnavailable::class);
        $repository->refresh(new RefreshBalanceData('7', '42'));
    }

    public function test_zero_net_movement_still_recalculates_totals_and_versions(): void
    {
        $repository = $this->prepare();
        $this->app->make(JournalRepository::class)->post(new PostingBatchData('7', [PostingBatches::entry('Offset #682', '494618', 'Receita')]));
        $result = $repository->refresh(new RefreshBalanceData('7', '42'));
        self::assertTrue($result->recalculated);
        self::assertSame('0', $result->balance->balanceMinor);
        self::assertSame('494618', $result->balance->debitTotalMinor);
        self::assertSame('494618', $result->balance->creditTotalMinor);
        self::assertSame('2', $result->balance->calculatedVersion);
    }

    public function test_invalidation_age_is_preserved_until_refresh_and_bookkeeping_is_unchanged(): void
    {
        $repository = $this->prepare();
        $first = DB::table('account_balances')->where('account_id', 42)->value('staled_since');
        self::assertNotNull($first);
        $this->app->make(JournalRepository::class)->post(new PostingBatchData('7', [PostingBatches::entry('Later #682', '100', 'Receita')]));
        self::assertSame($first, DB::table('account_balances')->where('account_id', 42)->value('staled_since'));
        $before = $this->bookkeeping();
        $result = $repository->refresh(new RefreshBalanceData('7', '42'));
        self::assertNull(DB::table('account_balances')->where('account_id', 42)->value('staled_since'));
        $again = $repository->refresh(new RefreshBalanceData('7', '42'));
        self::assertFalse($again->recalculated);
        self::assertEquals($result->balance, $again->balance);
        self::assertSame($before, $this->bookkeeping());
        $this->app->make(JournalRepository::class)->post(new PostingBatchData('7', [PostingBatches::entry('Third #682', '50', 'Receita')]));
        self::assertGreaterThan($first, DB::table('account_balances')->where('account_id', 42)->value('staled_since'));
    }

    public function test_projector_command_updates_pending_financial_and_technical_accounts_without_redis(): void
    {
        $this->prepare();
        config(['cache.default' => 'redis', 'database.redis.default.host' => '127.0.0.1', 'database.redis.default.port' => 1]);
        self::assertSame(0, Artisan::call('balances:project', ['--once' => true]));
        $metrics = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(2, $metrics['recalculated']);
        self::assertSame(2, $metrics['pending_before']);
        self::assertNotNull($metrics['oldest_staled_at']);
        self::assertSame(-494618, DB::table('account_balances')->where('account_id', 42)->value('balance_minor'));
        self::assertSame(494618, DB::table('account_balances')->where('account_id', 7002)->value('balance_minor'));
        self::assertSame(0, DB::table('account_balances')->where('staled', true)->count());
        self::assertSame(0, Artisan::call('balances:project', ['--once' => true]));
        self::assertSame(0, json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR)['examined']);
    }

    public function test_version_mismatch_is_recalculated_defensively_even_if_flag_is_false(): void
    {
        $repository = $this->prepare();
        // Simulate corrupt legacy metadata only in the guarded disposable test database.
        DB::statement('ALTER TABLE account_balances ALTER CHECK account_balances_fresh_check NOT ENFORCED');
        try {
            DB::table('account_balances')->where('account_id', 42)->update(['staled' => false]);
            self::assertSame(['42', '7002'], array_column($repository->pending('0')->accounts, 'id'));
            $result = $repository->refresh(new RefreshBalanceData('7', '42'));
            self::assertTrue($result->recalculated);
            self::assertSame('-494618', $result->balance->balanceMinor);
            self::assertSame('1', $result->balance->calculatedVersion);
        } finally {
            DB::table('account_balances')->whereColumn('ledger_version', '<>', 'calculated_version')->update(['staled' => true]);
            DB::statement('ALTER TABLE account_balances ALTER CHECK account_balances_fresh_check ENFORCED');
        }
    }

    /** @return array<string, mixed> */
    private function bookkeeping(): array
    {
        return ['journals' => DB::table('journal_entries')->count(), 'postings' => DB::table('ledger_entries')->count(),
            'events' => DB::table('outbox_events')->count(), 'deliveries' => DB::table('outbox_deliveries')->count(),
            'revision' => DB::table('financial_states')->where('owner_user_id', 7)->value('revision')];
    }
}
