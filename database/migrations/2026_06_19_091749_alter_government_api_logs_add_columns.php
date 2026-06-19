<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('government_api_logs', function (Blueprint $table) {
            $table->string('ip_address')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('government_api_logs', function (Blueprint $table) {
            $table->dropColumn('ip_address');
        });
    }
};
