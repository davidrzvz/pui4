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
        Schema::table('client_records', function (Blueprint $table) {
            $table->index('institution_id');
            $table->index('curp');
            $table->unique(['institution_id', 'curp']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('client_records', function (Blueprint $table) {
            $table->dropUnique(['institution_id', 'curp']);
            $table->dropIndex(['curp']);
            $table->dropIndex(['institution_id']);
        });
    }
};
