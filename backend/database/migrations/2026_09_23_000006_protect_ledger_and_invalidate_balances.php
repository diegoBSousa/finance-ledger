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
        // This import format has exactly two positions: a completed operation cannot gain a third leg.
        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_two_positions_check CHECK (position IN (1, 2))');
        foreach (['journal_entries', 'ledger_entries'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                $name = $table.'_immutable_'.strtolower($operation);
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Accounting entries are immutable'");
            }
        }
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER ledger_entries_invalidate_balances AFTER INSERT ON ledger_entries FOR EACH ROW
            BEGIN
                UPDATE financial_states SET revision = revision + 1 WHERE owner_user_id = NEW.owner_user_id;
                IF ROW_COUNT() <> 1 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Financial state is missing';
                END IF;
                UPDATE account_balances SET ledger_version = ledger_version + 1, staled = 1
                    WHERE account_id = NEW.account_id;
                IF ROW_COUNT() <> 1 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Account balance projection is missing';
                END IF;
            END
            SQL);

        // Earlier stages expose no financial writer. Still invalidate any pre-existing SQL-written rows.
        DB::statement(<<<'SQL'
            UPDATE account_balances b
            JOIN (SELECT account_id, COUNT(*) AS entries_count FROM ledger_entries GROUP BY account_id) l
                ON l.account_id = b.account_id
            SET b.staled = 1, b.ledger_version = GREATEST(b.ledger_version, l.entries_count)
            SQL);
        DB::statement(<<<'SQL'
            UPDATE financial_states s
            JOIN (SELECT owner_user_id, COUNT(*) AS entries_count FROM ledger_entries GROUP BY owner_user_id) l
                ON l.owner_user_id = s.owner_user_id
            SET s.revision = s.revision + l.entries_count
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_invalidate_balances');
        foreach (['journal_entries', 'ledger_entries'] as $table) {
            foreach (['update', 'delete'] as $operation) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_immutable_{$operation}");
            }
        }
        DB::statement('ALTER TABLE ledger_entries DROP CHECK ledger_two_positions_check');
    }
};
