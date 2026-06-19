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
        Schema::create('government_api_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->nullable()->constrained()->nullOnDelete();
            $table->string('endpoint');
            $table->string('method');
            $table->integer('status_code')->nullable();
            $table->json('request_data')->nullable();
            $table->json('response_data')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('government_api_logs');
    }
};
