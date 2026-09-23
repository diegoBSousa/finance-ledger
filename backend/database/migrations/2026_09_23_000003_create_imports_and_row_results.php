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
            CREATE TABLE imports (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                uploaded_by_user_id BIGINT UNSIGNED NOT NULL,
                file_path VARCHAR(1024) NOT NULL,
                file_size_bytes INT UNSIGNED NOT NULL,
                file_checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
                processed_rows BIGINT UNSIGNED NOT NULL DEFAULT 0,
                inserted_rows BIGINT UNSIGNED NOT NULL DEFAULT 0,
                duplicate_rows BIGINT UNSIGNED NOT NULL DEFAULT 0,
                rejected_rows BIGINT UNSIGNED NOT NULL DEFAULT 0,
                byte_offset INT UNSIGNED NOT NULL DEFAULT 0,
                last_record_number BIGINT UNSIGNED NOT NULL DEFAULT 0,
                checkpoint_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
                lease_token CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
                lease_expires_at TIMESTAMP(6) NULL,
                heartbeat_at TIMESTAMP(6) NULL,
                started_at TIMESTAMP(6) NULL,
                finished_at TIMESTAMP(6) NULL,
                last_error TEXT NULL,
                created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                KEY imports_uploader_id_index (uploaded_by_user_id, id),
                KEY imports_status_lease_id_index (status, lease_expires_at, id),
                CONSTRAINT imports_uploader_fk FOREIGN KEY (uploaded_by_user_id) REFERENCES users (id),
                CONSTRAINT imports_size_check CHECK (file_size_bytes > 0 AND file_size_bytes <= 100000000),
                CONSTRAINT imports_offset_check CHECK (byte_offset <= file_size_bytes),
                CONSTRAINT imports_checksum_check CHECK (REGEXP_LIKE(file_checksum, '^[0-9a-f]{64}$', 'c')),
                CONSTRAINT imports_status_check CHECK (status IN ('pending', 'processing', 'completed', 'completed_with_errors', 'failed')),
                CONSTRAINT imports_counters_check CHECK (processed_rows = inserted_rows + duplicate_rows + rejected_rows),
                CONSTRAINT imports_lease_check CHECK (
                    (lease_token IS NULL AND lease_expires_at IS NULL)
                    OR (lease_token IS NOT NULL AND lease_expires_at IS NOT NULL)
                )
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE import_rows (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                import_id BIGINT UNSIGNED NOT NULL,
                source_record_number BIGINT UNSIGNED NOT NULL,
                source_row_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
                status VARCHAR(9) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                journal_entry_id BIGINT UNSIGNED NULL,
                error_code VARCHAR(128) NULL,
                error_message TEXT NULL,
                created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                UNIQUE KEY import_rows_record_unique (import_id, source_record_number),
                KEY import_rows_import_status_id_index (import_id, status, id),
                CONSTRAINT import_rows_import_fk FOREIGN KEY (import_id) REFERENCES imports (id),
                CONSTRAINT import_rows_journal_fk FOREIGN KEY (journal_entry_id) REFERENCES journal_entries (id),
                CONSTRAINT import_rows_record_check CHECK (source_record_number > 0),
                CONSTRAINT import_rows_hash_check CHECK (source_row_hash IS NULL OR REGEXP_LIKE(source_row_hash, '^[0-9a-f]{64}$', 'c')),
                CONSTRAINT import_rows_result_check CHECK (
                    (status IN ('inserted', 'duplicate') AND journal_entry_id IS NOT NULL AND source_row_hash IS NOT NULL AND error_code IS NULL)
                    OR (status = 'rejected' AND journal_entry_id IS NULL AND error_code IS NOT NULL)
                )
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('imports');
    }
};
