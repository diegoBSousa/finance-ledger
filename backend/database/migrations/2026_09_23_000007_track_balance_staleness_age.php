<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $logging = DB::selectOne('SELECT @@log_bin AS enabled, @@log_bin_trust_function_creators AS trusted');
        if ($logging->enabled && ! $logging->trusted) {
            throw new RuntimeException('Trigger migrations require log_bin_trust_function_creators=1 when binary logging is enabled. Run scripts/bootstrap.sh with the updated Compose configuration.');
        }
        DB::statement('ALTER TABLE account_balances ADD COLUMN staled_since TIMESTAMP(6) NULL');
        // Existing pending projections have no historical invalidation timestamp; age starts at upgrade.
        DB::statement('UPDATE account_balances SET staled_since = CURRENT_TIMESTAMP(6) WHERE staled = 1');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER account_balances_track_staleness BEFORE UPDATE ON account_balances FOR EACH ROW
            BEGIN
                IF NEW.staled = 1 OR NEW.ledger_version <> NEW.calculated_version THEN
                    SET NEW.staled_since = COALESCE(OLD.staled_since, CURRENT_TIMESTAMP(6));
                ELSE
                    SET NEW.staled_since = NULL;
                END IF;
            END
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS account_balances_track_staleness');
        DB::statement('ALTER TABLE account_balances DROP COLUMN staled_since');
    }
};
