<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pui_reports', function (Blueprint $table) {
            $table->foreignUuid('client_record_id')->nullable()->constrained()->nullOnDelete();
            $table->string('curp')->nullable();
            $table->string('external_id')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pui_reports', function (Blueprint $table) {
            $table->dropForeign(['client_record_id']);
            $table->dropColumn(['client_record_id', 'curp', 'external_id', 'activated_at', 'deactivated_at']);
        });
    }
};
