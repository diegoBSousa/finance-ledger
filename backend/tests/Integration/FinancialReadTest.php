<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Accounting\Contracts\JournalRepository;
use App\Application\Accounting\Data\PostingBatchData;
use App\Application\Auth\Contracts\TokenService;
use App\Application\Dashboard\CacheUnavailable;
use App\Application\Dashboard\Contracts\DashboardCache;
use App\Application\Dashboard\Contracts\DashboardRepository;
use App\Application\Dashboard\Data\GetDashboardRequest;
use App\Application\Dashboard\GetDashboardUseCase;
use App\Application\Pagination\PageRequest;
use App\Application\Shared\ReadUnavailable;
use App\Application\Transactions\Contracts\LedgerReadRepository;
use App\Application\Transactions\Data\TransactionQueryData;
use App\Infrastructure\Persistence\ConsistentRead;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\Concerns\MakesHttpRequests;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Tests\Core\Support\PostingBatches;
use Tests\Integration\Support\UsesCsvImports;
use Tests\Integration\Support\UsesDashboardRedis;

final class FinancialReadTest extends TestCase
{
    use MakesHttpRequests;
    use UsesCsvImports { setUp as private importSetup;
        tearDown as private importCleanup; }
    use UsesDashboardRedis;

    protected function setUp(): void
    {
        $this->importSetup();
        $this->prepareRedis();
        $this->withToken($this->app->make(TokenService::class)->issue('7')->accessToken);
    }

    protected function tearDown(): void
    {
        $this->cleanupRedis();
        $this->importCleanup();
    }

    private function post(string $amount = '10', string $type = 'Receita', string $description = 'Income #682'): void
    {
        $this->app->make(JournalRepository::class)->post(new PostingBatchData('7', [PostingBatches::entry($description, $amount, $type)]));
    }

    public function test_dashboard_uses_committed_financial_legs_and_ignores_dirty_projections(): void
    {
        $this->post('100');
        $this->post('150', 'Despesa', 'Expense #682');
        $this->app->make(JournalRepository::class)->post(new PostingBatchData('8', [PostingBatches::entry('Foreign #683', '777', 'Receita', '8')]));
        $first = $this->getJson('/api/v1/dashboard?owner_user_id=8&per_page=1')->assertOk()->assertExactJson(['data' => [
            'currency' => 'BRL', 'income_minor' => '100', 'expense_minor' => '150', 'balance_minor' => '-50', 'transaction_count' => '2', 'financial_revision' => '4']]);
        self::assertStringContainsString('no-store', $first->headers->get('Cache-Control'));
        self::assertSame(1, DB::table('account_balances')->where('account_id', 42)->value('staled'));
        $this->getJson('/api/v1/dashboard')->assertOk()->assertExactJson($first->json());
        $this->post('100'); // Duplicate: neither revision nor totals advance.
        $this->getJson('/api/v1/dashboard')->assertJsonPath('data.financial_revision', '4');
        $this->post('20', 'Receita', 'New #682');
        // Invalidation jobs remain pending, but the revision already prevents an old cache hit.
        $this->getJson('/api/v1/dashboard')->assertJsonPath('data.income_minor', '120')->assertJsonPath('data.financial_revision', '6');
    }

    public function test_empty_owner_has_zero_totals_and_large_centavos_remain_strings(): void
    {
        $this->createUser('9');
        $this->withToken($this->app->make(TokenService::class)->issue('9')->accessToken);
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.balance_minor', '0')->assertJsonPath('data.financial_revision', '0');
        $this->post((string) PHP_INT_MAX);
        $this->withToken($this->app->make(TokenService::class)->issue('7')->accessToken);
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.income_minor', (string) PHP_INT_MAX);
        $this->getJson('/api/v1/transactions')->assertOk()->assertJsonPath('data.0.amount_minor', (string) PHP_INT_MAX);
    }

    public function test_redis_failure_falls_back_but_sql_failure_and_overflow_return_503(): void
    {
        $this->post();
        $this->getJson('/api/v1/dashboard')->assertOk();
        config(['database.redis.dashboard.port' => 1]);
        $this->reloadRedisConfiguration();
        $cacheFailed = false;
        try {
            $this->app->make(DashboardCache::class)->get('7', '2');
        } catch (CacheUnavailable) {
            $cacheFailed = true;
        }
        self::assertTrue($cacheFailed, 'The fault must reach the actual Redis connection.');
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.income_minor', '10');
        $this->post((string) PHP_INT_MAX, 'Receita', 'Overflow #682');
        $this->getJson('/api/v1/dashboard')->assertStatus(503)->assertJsonPath('code', 'financial_read_unavailable')->assertJsonMissingPath('data');
        config(['database.connections.ledger_read.port' => 1]);
        DB::purge(ConsistentRead::CONNECTION);
        $this->getJson('/api/v1/dashboard')->assertStatus(503)->assertJsonMissingPath('data');
    }

    public function test_revision_and_aggregate_share_snapshot_during_concurrent_commit(): void
    {
        $this->post();
        $written = false;
        DB::listen(function (QueryExecuted $event) use (&$written): void {
            if (! $written && $event->connectionName === ConsistentRead::CONNECTION && str_contains($event->sql, 'financial_states')) {
                $written = true;
                $this->post('20', 'Receita', 'Concurrent #682');
            }
        });
        $snapshot = $this->app->make(DashboardRepository::class)->snapshot('7');
        self::assertTrue($written);
        self::assertSame('2', $snapshot->revision);
        self::assertSame('10', $snapshot->incomeMinor);
        $cache = $this->app->make(DashboardCache::class);
        $cache->put($snapshot);
        $current = $this->app->make(GetDashboardUseCase::class)->execute(new GetDashboardRequest('7'))->dashboard;
        self::assertSame('4', $current->revision);
        self::assertSame('30', $current->incomeMinor);
    }

    public function test_reads_ignore_uncommitted_writer_changes_and_reject_ambient_reader_transaction(): void
    {
        $this->post();
        DB::beginTransaction();
        $this->post('20', 'Receita', 'Uncommitted #682');
        self::assertSame('10', $this->app->make(DashboardRepository::class)->snapshot('7')->incomeMinor);
        DB::rollBack();
        DB::connection(ConsistentRead::CONNECTION)->beginTransaction();
        $this->expectException(ReadUnavailable::class);
        $this->app->make(DashboardRepository::class)->revision('7');
    }

    public function test_transaction_and_account_pages_are_scoped_sorted_filtered_and_capped(): void
    {
        $id = $this->uploadCsv($this->csv(12)."2026-08-15,Old #682,20,Despesa\n");
        $this->consumeImport($id);
        $this->getJson('/api/v1/transactions?owner_user_id=8')->assertOk()->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 13)->assertJsonPath('data.0.description', 'Row 12')->assertJsonPath('data.0.type', 'income')
            ->assertJsonMissingPath('data.0.owner_user_id');
        $this->getJson('/api/v1/transactions?page=2')->assertJsonCount(3, 'data')->assertJsonPath('data.2.description', 'Old');
        $this->getJson('/api/v1/transactions?date_from=2026-08-15&date_to=2026-08-15&type=expense')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.amount_minor', '20');
        $this->getJson('/api/v1/accounts/682/transactions?account_number=683&per_page=1')->assertOk()->assertJsonPath('data.0.account_number', '682');
        foreach (['683', '999'] as $number) {
            $this->getJson('/api/v1/accounts/'.$number.'/transactions')->assertNotFound();
        }
        $this->getJson('/api/v1/transactions?account_number=683')->assertNotFound();
        DB::table('accounts')->where('id', 42)->update(['active' => false]);
        $this->getJson('/api/v1/accounts/682/transactions')->assertOk()->assertJsonCount(10, 'data');
        $this->getJson('/api/v1/accounts')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.active', false)->assertJsonMissingPath('data.0.balance_minor');
        $this->getJson('/api/v1/accounts?account_number=683')->assertJsonCount(0, 'data');
    }

    public function test_transaction_count_and_page_share_snapshot(): void
    {
        $this->post();
        $written = false;
        DB::listen(function (QueryExecuted $event) use (&$written): void {
            if (! $written && $event->connectionName === ConsistentRead::CONNECTION && str_contains($event->sql, 'count(*)')) {
                $written = true;
                $this->post('20', 'Receita', 'Concurrent #682');
            }
        });
        $page = $this->app->make(LedgerReadRepository::class)->page(new TransactionQueryData('7', new PageRequest));
        self::assertTrue($written);
        self::assertSame(1, $page->total);
        self::assertCount(1, $page->transactions);
        self::assertSame('Income', $page->transactions[0]->description);
    }

    public function test_import_rows_errors_and_duplicates_are_private_and_paginated(): void
    {
        $id = $this->uploadCsv($this->csv(11)."2026-08-16,Unknown #999,20,Receita\n2026-08-16,Row 1 #682,10,Receita\n");
        $this->consumeImport($id);
        $this->getJson('/api/v1/imports/'.$id.'/rows')->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 13)->assertJsonPath('data.0.source_record_number', '2');
        $this->getJson('/api/v1/imports/'.$id.'/rows?page=2')->assertJsonCount(3, 'data')->assertJsonPath('data.1.status', 'rejected')->assertJsonPath('data.2.status', 'duplicate');
        $this->getJson('/api/v1/imports/'.$id.'/rows?status=duplicate')->assertJsonCount(1, 'data')->assertJsonPath('data.0.source_record_number', '14');
        $this->getJson('/api/v1/imports/'.$id.'/errors?status=inserted')->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'rejected')->assertJsonMissingPath('data.0.file_path');
        $empty = $this->uploadCsv($this->csv(1, 100));
        $this->getJson('/api/v1/imports/'.$empty.'/rows')->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($this->app->make(TokenService::class)->issue('8')->accessToken);
        foreach ([$id, $empty, '99999'] as $candidate) {
            $this->getJson('/api/v1/imports/'.$candidate.'/rows')->assertNotFound()->assertJsonPath('code', 'import_not_found');
        }
    }
}
