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
            $table->timestamp('government_sent_at')->nullable();
            $table->string('government_status')->default('PENDIENTE_ENVIO');
            $table->json('government_response')->nullable();
            $table->text('government_error')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pui_reports', function (Blueprint $table) {
            $table->dropColumn([
                'government_sent_at',
                'government_status',
                'government_response',
                'government_error',
            ]);
        });
    }
};
