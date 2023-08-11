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
        Schema::table('project_settings', function (Blueprint $table) {
            $table->dropColumn('wildcard_domain');
        });
        Schema::table('server_settings', function (Blueprint $table) {
            $table->string('wildcard_domain')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_settings', function (Blueprint $table) {
            $table->string('wildcard_domain')->nullable();
        });
        Schema::table('server_settings', function (Blueprint $table) {
            $table->dropColumn('wildcard_domain');
        });
    }
};
