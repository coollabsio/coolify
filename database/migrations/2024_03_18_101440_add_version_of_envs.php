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
            $table->string('version')->default('4.0.0-beta.239');
        });
        Schema::table('shared_environment_variables', function (Blueprint $table) {
            $table->string('version')->default('4.0.0-beta.239');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('environment_variables', function (Blueprint $table) {
            $table->dropColumn('version');
        });
        Schema::table('shared_environment_variables', function (Blueprint $table) {
            $table->dropColumn('version');
        });
    }
};
