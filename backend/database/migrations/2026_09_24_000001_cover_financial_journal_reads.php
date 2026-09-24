<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Keep owner/date/id ordering and cover the count, filters and dashboard join.
        // Otherwise MySQL can scan owner/hash and fetch large TEXT rows in random order.
        DB::statement('ALTER TABLE journal_entries
            ADD INDEX journal_owner_read_index (owner_user_id, posting_date, id, financial_account_id, movement_type),
            DROP INDEX journal_owner_date_id_index');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE journal_entries
            ADD INDEX journal_owner_date_id_index (owner_user_id, posting_date, id),
            DROP INDEX journal_owner_read_index');
    }
};
