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
            $table->foreignId('standalone_mongodb_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('environment_variables', function (Blueprint $table) {
            $table->dropColumn('standalone_mongodb_id');
        });
    }
};
