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
        Schema::table('applications', function (Blueprint $table) {
            $table->boolean('is_http_basic_auth_enabled')->default(false);
            $table->string('http_basic_auth_username')->nullable(true)->default(null);
            $table->string('http_basic_auth_password')->nullable(true)->default(null);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('is_http_basic_auth_enabled');
            $table->dropColumn('http_basic_auth_username');
            $table->dropColumn('http_basic_auth_password');
        });
    }
};
