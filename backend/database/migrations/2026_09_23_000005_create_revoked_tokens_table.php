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
            CREATE TABLE revoked_tokens (
                token_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                expires_at BIGINT UNSIGNED NOT NULL,
                revoked_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                KEY revoked_tokens_expiry_index (expires_at),
                CONSTRAINT revoked_tokens_user_fk FOREIGN KEY (user_id) REFERENCES users (id),
                CONSTRAINT revoked_tokens_id_check CHECK (REGEXP_LIKE(token_id, '^[0-9a-f]{64}$', 'c')),
                CONSTRAINT revoked_tokens_expiry_check CHECK (expires_at > 0)
            ) ENGINE=InnoDB
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('revoked_tokens');
    }
};
