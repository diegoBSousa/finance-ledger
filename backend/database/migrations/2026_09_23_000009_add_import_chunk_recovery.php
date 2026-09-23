<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('imports', function (Blueprint $table): void {
            $table->json('file_block_hashes')->nullable();
            $table->unsignedTinyInteger('chunk_attempts')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('imports', fn (Blueprint $table) => $table->dropColumn(['file_block_hashes', 'chunk_attempts']));
    }
};
