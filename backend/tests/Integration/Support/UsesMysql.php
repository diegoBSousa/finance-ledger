<?php

declare(strict_types=1);

namespace Tests\Integration\Support;

use App\Domain\Accounting\Data\AccountData;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Support\Facades\DB;

trait UsesMysql
{
    protected Application $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = MysqlDatabase::application();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::disconnect();
        $this->app->flush();
        HandleExceptions::flushState($this);
        parent::tearDown();
    }

    protected function createUser(string $id = '7'): void
    {
        DB::table('users')->insert([
            'id' => $id,
            'name' => 'Fixture '.$id,
            'email' => 'fixture-'.$id.'@example.test',
            // No authentication in these fixtures; demo password hashing is tested separately.
            'password' => 'unused-fixture-password',
        ]);
    }

    /** @param list<AccountData> $accounts */
    protected function persistAccounts(array $accounts): void
    {
        $owners = array_unique(array_map(fn (AccountData $data) => $data->ownerUserId, $accounts));
        foreach ($owners as $owner) {
            $this->createUser($owner);
            DB::table('financial_states')->insert(['owner_user_id' => $owner]);
        }
        foreach ($accounts as $account) {
            DB::table('accounts')->insert([
                'id' => $account->id,
                'owner_user_id' => $account->ownerUserId,
                'external_number' => $account->externalNumber,
                'kind' => $account->kind,
                'currency' => $account->currency,
                'active' => $account->active,
            ]);
            DB::table('account_balances')->insert(['account_id' => $account->id, 'calculated_at' => now()]);
        }
    }

    /** @return array<string, mixed> */
    protected function journalData(string $hash = '', string $owner = '7', string $account = '42'): array
    {
        return [
            'owner_user_id' => $owner,
            'uploaded_by_user_id' => $owner,
            'financial_account_id' => $account,
            'posting_date' => '2026-08-16',
            'description' => 'Serviços de Limpeza',
            'original_description' => 'Serviços de Limpeza #682',
            'movement_type' => 'expense',
            'source_row_hash' => $hash !== '' ? $hash : hash('sha256', 'fixture'),
            'canonical_record' => '["fixture"]',
        ];
    }

    /** @return array<string, mixed> */
    protected function ledgerData(int $journal, string $account = '42'): array
    {
        return [
            'journal_entry_id' => $journal,
            'owner_user_id' => '7',
            'account_id' => $account,
            'position' => 1,
            'side' => 'debit',
            'amount_minor' => 494618,
        ];
    }

    /** @return array<string, mixed> */
    protected function importData(): array
    {
        return [
            'uploaded_by_user_id' => '7',
            'file_path' => 'uploads/fixture.csv',
            'file_size_bytes' => 100000000,
            'file_checksum' => hash('sha256', 'fixture-file'),
        ];
    }
}
