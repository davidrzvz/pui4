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
        Schema::table('government_api_logs', function (Blueprint $table) {
            $table->uuid('pui_report_id')->nullable()->index()->after('id');
            $table->foreign('pui_report_id')->references('id')->on('pui_reports')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('government_api_logs', function (Blueprint $table) {
            $table->dropForeign(['pui_report_id']);
            $table->dropColumn('pui_report_id');
        });
    }
};
