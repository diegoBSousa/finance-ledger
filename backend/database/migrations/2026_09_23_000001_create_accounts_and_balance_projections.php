<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE financial_states (
                owner_user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
                CONSTRAINT financial_states_owner_fk FOREIGN KEY (owner_user_id) REFERENCES users (id)
            ) ENGINE=InnoDB
            SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE accounts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                owner_user_id BIGINT UNSIGNED NOT NULL,
                external_number BIGINT NULL,
                kind VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                technical_kind VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin
                    GENERATED ALWAYS AS (CASE WHEN kind = 'asset' THEN NULL ELSE kind END) STORED,
                currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'BRL',
                active TINYINT NOT NULL DEFAULT 1,
                created_at TIMESTAMP(6) NULL,
                updated_at TIMESTAMP(6) NULL,
                UNIQUE KEY accounts_external_number_unique (external_number),
                UNIQUE KEY accounts_owner_technical_unique (owner_user_id, technical_kind),
                UNIQUE KEY accounts_id_owner_currency_unique (id, owner_user_id, currency),
                KEY accounts_owner_kind_id_index (owner_user_id, kind, id),
                CONSTRAINT accounts_owner_fk FOREIGN KEY (owner_user_id) REFERENCES users (id),
                CONSTRAINT accounts_kind_check CHECK (kind IN ('asset', 'revenue', 'expense')),
                CONSTRAINT accounts_number_check CHECK (
                    (kind = 'asset' AND external_number IS NOT NULL AND external_number > 0)
                    OR (kind IN ('revenue', 'expense') AND external_number IS NULL)
                ),
                CONSTRAINT accounts_currency_check CHECK (currency = 'BRL'),
                CONSTRAINT accounts_active_check CHECK (active IN (0, 1))
            ) ENGINE=InnoDB
            SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE account_balances (
                account_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                debit_total_minor BIGINT NOT NULL DEFAULT 0,
                credit_total_minor BIGINT NOT NULL DEFAULT 0,
                balance_minor BIGINT NOT NULL DEFAULT 0,
                staled TINYINT NOT NULL DEFAULT 0,
                ledger_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
                calculated_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
                calculated_at TIMESTAMP(6) NULL,
                KEY account_balances_staled_account_index (staled, account_id),
                CONSTRAINT account_balances_account_fk FOREIGN KEY (account_id) REFERENCES accounts (id),
                CONSTRAINT account_balances_totals_check CHECK (debit_total_minor >= 0 AND credit_total_minor >= 0),
                CONSTRAINT account_balances_staled_check CHECK (staled IN (0, 1)),
                CONSTRAINT account_balances_versions_check CHECK (calculated_version <= ledger_version),
                CONSTRAINT account_balances_fresh_check CHECK (staled = 1 OR calculated_version = ledger_version)
            ) ENGINE=InnoDB
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('account_balances');
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('financial_states');
    }
};
