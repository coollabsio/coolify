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
        Schema::table('application_previews', function (Blueprint $table) {
            $table->text('docker_compose_domains')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('application_previews', function (Blueprint $table) {
            $table->dropColumn('docker_compose_domains');
        });
    }
};
