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
            CREATE TABLE journal_entries (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                owner_user_id BIGINT UNSIGNED NOT NULL,
                financial_account_id BIGINT UNSIGNED NOT NULL,
                uploaded_by_user_id BIGINT UNSIGNED NOT NULL,
                posting_date DATE NOT NULL,
                description TEXT NOT NULL,
                original_description TEXT NOT NULL,
                movement_type VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'BRL',
                source_row_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                canonical_record TEXT NOT NULL,
                created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                UNIQUE KEY journal_owner_hash_unique (owner_user_id, source_row_hash),
                UNIQUE KEY journal_id_owner_currency_unique (id, owner_user_id, currency),
                KEY journal_owner_date_id_index (owner_user_id, posting_date, id),
                KEY journal_account_date_id_index (financial_account_id, posting_date, id),
                CONSTRAINT journal_owner_fk FOREIGN KEY (owner_user_id) REFERENCES users (id),
                CONSTRAINT journal_uploader_fk FOREIGN KEY (uploaded_by_user_id) REFERENCES users (id),
                CONSTRAINT journal_financial_account_fk FOREIGN KEY (financial_account_id, owner_user_id, currency)
                    REFERENCES accounts (id, owner_user_id, currency),
                CONSTRAINT journal_movement_check CHECK (movement_type IN ('income', 'expense')),
                CONSTRAINT journal_currency_check CHECK (currency = 'BRL'),
                CONSTRAINT journal_hash_check CHECK (REGEXP_LIKE(source_row_hash, '^[0-9a-f]{64}$', 'c'))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE ledger_entries (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                journal_entry_id BIGINT UNSIGNED NOT NULL,
                owner_user_id BIGINT UNSIGNED NOT NULL,
                account_id BIGINT UNSIGNED NOT NULL,
                position SMALLINT UNSIGNED NOT NULL,
                side VARCHAR(6) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                amount_minor BIGINT NOT NULL,
                currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'BRL',
                created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                UNIQUE KEY ledger_journal_position_unique (journal_entry_id, position),
                KEY ledger_account_journal_index (account_id, journal_entry_id),
                CONSTRAINT ledger_journal_fk FOREIGN KEY (journal_entry_id, owner_user_id, currency)
                    REFERENCES journal_entries (id, owner_user_id, currency),
                CONSTRAINT ledger_account_fk FOREIGN KEY (account_id, owner_user_id, currency)
                    REFERENCES accounts (id, owner_user_id, currency),
                CONSTRAINT ledger_position_check CHECK (position > 0),
                CONSTRAINT ledger_side_check CHECK (side IN ('debit', 'credit')),
                CONSTRAINT ledger_amount_check CHECK (amount_minor > 0),
                CONSTRAINT ledger_currency_check CHECK (currency = 'BRL')
            ) ENGINE=InnoDB
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('journal_entries');
    }
};
