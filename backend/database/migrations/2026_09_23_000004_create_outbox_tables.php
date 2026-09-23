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
            CREATE TABLE outbox_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                event_type VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                event_version SMALLINT UNSIGNED NOT NULL,
                payload JSON NOT NULL,
                occurred_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                CONSTRAINT outbox_events_version_check CHECK (event_version > 0),
                CONSTRAINT outbox_events_payload_check CHECK (JSON_TYPE(payload) = 'OBJECT')
            ) ENGINE=InnoDB
            SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE outbox_deliveries (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                event_id BIGINT UNSIGNED NOT NULL,
                consumer VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                status VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
                attempts INT UNSIGNED NOT NULL DEFAULT 0,
                available_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                lease_token CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
                lease_expires_at TIMESTAMP(6) NULL,
                published_at TIMESTAMP(6) NULL,
                acknowledged_at TIMESTAMP(6) NULL,
                last_error TEXT NULL,
                UNIQUE KEY outbox_delivery_consumer_unique (event_id, consumer),
                KEY outbox_delivery_pending_index (status, available_at, id),
                KEY outbox_delivery_lease_index (status, lease_expires_at, id),
                CONSTRAINT outbox_delivery_event_fk FOREIGN KEY (event_id) REFERENCES outbox_events (id),
                CONSTRAINT outbox_delivery_status_check CHECK (status IN ('pending', 'publishing', 'published', 'acknowledged', 'failed')),
                CONSTRAINT outbox_delivery_lease_check CHECK (
                    (lease_token IS NULL AND lease_expires_at IS NULL)
                    OR (lease_token IS NOT NULL AND lease_expires_at IS NOT NULL)
                )
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_deliveries');
        Schema::dropIfExists('outbox_events');
    }
};
