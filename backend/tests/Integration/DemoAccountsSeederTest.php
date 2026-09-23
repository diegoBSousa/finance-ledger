<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Accounting\Data\AccountData;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Integration\Support\UsesMysql;

final class DemoAccountsSeederTest extends TestCase
{
    use UsesMysql;

    public function test_seed_is_repeatable_and_preserves_passwords_balances_revisions_and_account_state(): void
    {
        $this->seedDemo();
        $user = DB::table('users')->where('email', 'demo@example.test')->first();
        self::assertSame('argon2id', password_get_info($user->password)['algoName']);
        self::assertTrue(Hash::check('local-demo-test-password', $user->password));
        self::assertSame(1, DB::table('users')->count());
        self::assertSame(902, DB::table('accounts')->count());
        self::assertSame(902, DB::table('account_balances')->count());
        self::assertSame(1, DB::table('financial_states')->count());
        self::assertSame(0, DB::table('account_balances')->where('balance_minor', '!=', 0)->count());
        self::assertSame(0, DB::table('account_balances')->where('staled', 1)->count());
        self::assertSame(range(100, 999), DB::table('accounts')->where('kind', 'asset')->orderBy('external_number')->pluck('external_number')->all());
        self::assertSame([(int) $user->id], DB::table('accounts')->distinct()->pluck('owner_user_id')->all());

        $account = DB::table('accounts')->where('external_number', 682)->first();
        DB::table('account_balances')->where('account_id', $account->id)->update([
            'balance_minor' => -494618, 'credit_total_minor' => 494618,
            'staled' => 1, 'ledger_version' => 3,
        ]);
        DB::table('financial_states')->where('owner_user_id', $user->id)->update(['revision' => 10]);
        DB::table('accounts')->where('id', $account->id)->update(['active' => 0]);
        $before = DB::table('account_balances')->where('account_id', $account->id)->first();

        config(['demo.password' => 'a-different-demo-password']);
        Artisan::call('db:seed', ['--force' => true]);

        self::assertSame(1, DB::table('users')->count());
        self::assertSame(902, DB::table('accounts')->count());
        self::assertSame(902, DB::table('account_balances')->count());
        self::assertEquals($before, DB::table('account_balances')->where('account_id', $account->id)->first());
        self::assertSame(10, DB::table('financial_states')->value('revision'));
        self::assertSame(0, DB::table('accounts')->where('id', $account->id)->value('active'));
        self::assertSame($user->password, DB::table('users')->where('id', $user->id)->value('password'));
        self::assertSame(0, DB::table('journal_entries')->count());
        self::assertSame(0, DB::table('ledger_entries')->count());
    }

    public function test_partial_seed_creates_missing_accounts_without_replacing_existing_ones(): void
    {
        $this->persistAccounts([new AccountData('42', '7', '682', 'asset', active: false)]);
        DB::table('users')->where('id', 7)->update(['email' => 'demo@example.test']);
        $this->seedDemo();

        self::assertSame(902, DB::table('accounts')->count());
        self::assertSame(42, DB::table('accounts')->where('external_number', 682)->value('id'));
        self::assertSame(0, DB::table('accounts')->where('external_number', 682)->value('active'));
    }

    public function test_conflicting_ownership_rolls_back_the_whole_seed(): void
    {
        $this->persistAccounts([new AccountData('42', '7', '682', 'asset')]);
        try {
            $this->seedDemo();
            self::fail('A seed must not reassign an existing account.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('#682', $exception->getMessage());
        }

        self::assertSame(1, DB::table('users')->count());
        self::assertSame(1, DB::table('accounts')->count());
        self::assertSame(1, DB::table('account_balances')->count());
        self::assertSame(1, DB::table('financial_states')->count());
        self::assertSame(7, DB::table('accounts')->value('owner_user_id'));
    }

    public function test_seed_rejects_production_even_when_force_is_used(): void
    {
        $this->app->instance('env', 'production');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('local or testing');
        $this->seedDemo();
    }

    public function test_seed_requires_an_explicit_password(): void
    {
        config(['demo.email' => 'demo@example.test', 'demo.password' => null]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DEMO_USER_PASSWORD');
        Artisan::call('db:seed', ['--force' => true]);
    }

    #[DataProvider('missingProjectionTables')]
    public function test_seed_does_not_reinitialize_missing_projection_data(string $table): void
    {
        $this->persistAccounts([new AccountData('42', '7', '682', 'asset')]);
        DB::table('users')->where('id', 7)->update(['email' => 'demo@example.test']);
        DB::table($table)->delete();
        try {
            $this->seedDemo();
            self::fail('A missing projection must be reconciled, not silently reset.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('reconcile', $exception->getMessage());
        }
        self::assertSame(1, DB::table('accounts')->count());
        self::assertSame(0, DB::table($table)->count());
    }

    public static function missingProjectionTables(): iterable
    {
        yield 'balance' => ['account_balances'];
        yield 'financial revision' => ['financial_states'];
    }

    private function seedDemo(): void
    {
        config(['demo.email' => 'demo@example.test', 'demo.password' => 'local-demo-test-password']);
        Artisan::call('db:seed', ['--force' => true]);
    }
}
