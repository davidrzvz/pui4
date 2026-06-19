<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pui_report_match_checks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pui_report_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('institution_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('csv_import_batch_id')->nullable()->constrained('csv_import_batches')->nullOnDelete();
            $table->foreignUuid('client_record_id')->nullable()->constrained()->nullOnDelete();
            $table->string('match_status');
            $table->timestamp('checked_at');
            $table->foreignUuid('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pui_report_match_checks');
    }
};
