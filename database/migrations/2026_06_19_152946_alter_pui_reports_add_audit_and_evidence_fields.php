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
        Schema::table('pui_reports', function (Blueprint $table) {
            $table->uuid('government_sent_by')->nullable()->after('government_sent_at')->constrained('users')->nullOnDelete();
            $table->json('sent_evidence')->nullable()->after('government_error');
        });

        Schema::table('government_api_logs', function (Blueprint $table) {
            $table->uuid('operator_user_id')->nullable()->after('institution_id')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pui_reports', function (Blueprint $table) {
            $table->dropForeign(['government_sent_by']);
            $table->dropColumn(['government_sent_by', 'sent_evidence']);
        });

        Schema::table('government_api_logs', function (Blueprint $table) {
            $table->dropForeign(['operator_user_id']);
            $table->dropColumn('operator_user_id');
        });
    }
};
