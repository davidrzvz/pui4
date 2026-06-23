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
            $table->string('import_mode')->default('append')->after('status');
            $table->integer('deactivated_records')->nullable()->default(0)->after('failed_records');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('csv_import_batches', function (Blueprint $table) {
            $table->dropColumn(['import_mode', 'deactivated_records']);
        });
    }
};
