<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Balances\Data\GetAccountBalanceRequest;
use App\Application\Balances\GetAccountBalanceUseCase;
use App\Application\Dashboard\Contracts\DashboardRepository;
use App\Application\Pagination\PageRequest;
use App\Application\Transactions\Contracts\LedgerReadRepository;
use App\Application\Transactions\Data\TransactionQueryData;
use App\Domain\Accounting\Data\AccountData;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Tests\Integration\Support\UsesCsvImports;

final class ImportReferenceFileTest extends TestCase
{
    use UsesCsvImports;

    protected function importAccounts(): array
    {
        $accounts = [new AccountData('7001', '7', null, 'revenue'), new AccountData('7002', '7', null, 'expense')];
        for ($number = 100; $number <= 999; $number++) {
            $accounts[] = new AccountData((string) (10000 + $number), '7', (string) $number, 'asset');
        }

        return $accounts;
    }

    public function test_supplied_csv_totals_and_full_reimport_match_reference_without_duplicate_effects(): void
    {
        $content = file_get_contents(dirname(__DIR__).'/Fixtures/financial_transactions.csv');
        self::assertSame('f60074309616fa37ba606843875d6adf624281690c30375d8fb22253e1387893', hash('sha256', $content));
        $started = microtime(true);
        $id = $this->uploadCsv($content);
        $this->consumeImport($id);
        $firstSeconds = microtime(true) - $started;
        self::assertSame('completed', DB::table('imports')->find($id)->status);
        self::assertSame(15000, DB::table('imports')->find($id)->inserted_rows);
        self::assertSame(15000, DB::table('journal_entries')->count());
        self::assertSame(30000, DB::table('ledger_entries')->count());
        self::assertSame(900, DB::table('journal_entries')->distinct()->count('financial_account_id'));
        $totals = DB::selectOne("SELECT SUM(CASE WHEN j.movement_type = 'income' THEN l.amount_minor ELSE 0 END) AS income,
            SUM(CASE WHEN j.movement_type = 'expense' THEN l.amount_minor ELSE 0 END) AS expense
            FROM journal_entries j JOIN ledger_entries l ON l.journal_entry_id=j.id AND l.account_id=j.financial_account_id");
        self::assertSame('3611960974', (string) $totals->income);
        self::assertSame('2670954574', (string) $totals->expense);
        self::assertSame('6282915548', (string) DB::table('ledger_entries')->where('side', 'debit')->sum('amount_minor'));
        self::assertSame('6282915548', (string) DB::table('ledger_entries')->where('side', 'credit')->sum('amount_minor'));
        $balances = DB::table('ledger_entries as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('a.kind', 'asset')
            ->groupBy('a.external_number')->select('a.external_number')->selectRaw("SUM(CASE WHEN l.side='debit' THEN l.amount_minor ELSE -l.amount_minor END) AS balance")->get();
        self::assertSame(941006400, $balances->sum(fn ($row) => (int) $row->balance));
        self::assertSame(301, $balances->filter(fn ($row) => (int) $row->balance < 0)->count());
        self::assertSame('109209', (string) $balances->firstWhere('external_number', 682)->balance);
        self::assertSame(10, DB::table('journal_entries')->where('financial_account_id', '10682')->count());
        $balance = $this->app->make(GetAccountBalanceUseCase::class)->execute(new GetAccountBalanceRequest('7', '682'))->balance;
        self::assertSame('109209', $balance->balanceMinor);
        self::assertSame(0, DB::table('account_balances')->where('account_id', '10682')->value('staled'));
        $dashboard = $this->app->make(DashboardRepository::class)->snapshot('7');
        self::assertSame(['3611960974', '2670954574', '941006400', '15000'],
            [$dashboard->incomeMinor, $dashboard->expenseMinor, $dashboard->balanceMinor, $dashboard->transactionCount]);
        $statement = $this->app->make(LedgerReadRepository::class)->page(new TransactionQueryData('7', new PageRequest, '682'));
        self::assertSame(10, $statement->total);
        self::assertCount(10, $statement->transactions);
        $revision = DB::table('financial_states')->where('owner_user_id', '7')->value('revision');
        $events = DB::table('outbox_events')->where('event_type', 'LedgerChanged')->count();
        $started = microtime(true);
        $second = $this->uploadCsv($content);
        $this->consumeImport($second);
        self::assertSame([15000, 0, 15000, 0], array_values((array) DB::table('imports')->where('id', $second)->first(['processed_rows', 'inserted_rows', 'duplicate_rows', 'rejected_rows'])));
        self::assertSame('completed', DB::table('imports')->find($second)->status);
        self::assertSame(30000, DB::table('ledger_entries')->count());
        self::assertSame($revision, DB::table('financial_states')->where('owner_user_id', '7')->value('revision'));
        self::assertSame($events, DB::table('outbox_events')->where('event_type', 'LedgerChanged')->count());
        self::assertEquals($dashboard, $this->app->make(DashboardRepository::class)->snapshot('7'));
        fwrite(STDERR, json_encode(['reference_csv_records' => 15000, 'first_import_seconds' => round($firstSeconds, 3),
            'duplicate_import_seconds' => round(microtime(true) - $started, 3), 'php_peak_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR)."\n");
    }
}
