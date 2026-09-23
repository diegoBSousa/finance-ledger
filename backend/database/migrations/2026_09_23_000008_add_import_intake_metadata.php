<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE imports ADD COLUMN original_name VARCHAR(255) NOT NULL DEFAULT 'import.csv', ADD COLUMN prepared_at TIMESTAMP(6) NULL");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE imports DROP COLUMN prepared_at, DROP COLUMN original_name');
    }
};
