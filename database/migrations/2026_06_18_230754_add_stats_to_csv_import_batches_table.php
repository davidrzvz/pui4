<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('csv_import_batches', function (Blueprint $table) {
            $table->integer('created_records')->default(0)->after('processed_records');
            $table->integer('updated_records')->default(0)->after('created_records');
            $table->integer('failed_records')->default(0)->after('updated_records');
            $table->integer('duplicate_records')->default(0)->after('failed_records');
            $table->json('error_summary')->nullable()->after('duplicate_records');

            $table->index('institution_id');
            $table->index('user_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('csv_import_batches', function (Blueprint $table) {
            $table->dropIndex(['institution_id']);
            $table->dropIndex(['user_id']);
            $table->dropIndex(['status']);
            $table->dropColumn(['created_records', 'updated_records', 'failed_records', 'duplicate_records', 'error_summary']);
        });
    }
};
