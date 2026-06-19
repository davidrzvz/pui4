<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pui_reports', function (Blueprint $table) {
            $table->string('match_status')->nullable();
            $table->foreignUuid('matched_csv_import_batch_id')->nullable()->constrained('csv_import_batches')->nullOnDelete();
            $table->timestamp('match_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pui_reports', function (Blueprint $table) {
            $table->dropForeign(['matched_csv_import_batch_id']);
            $table->dropColumn(['match_status', 'matched_csv_import_batch_id', 'match_checked_at']);
        });
    }
};
