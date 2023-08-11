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
        Schema::table('environment_variables', function (Blueprint $table) {
            $table->dropColumn('service_id');
            $table->dropColumn('database_id');
            $table->foreignId('standalone_postgresql_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('environment_variables', function (Blueprint $table) {
            $table->foreignId('service_id')->nullable();
            $table->foreignId('database_id')->nullable();
            $table->dropColumn('standalone_postgresql_id');
        });
    }
};
