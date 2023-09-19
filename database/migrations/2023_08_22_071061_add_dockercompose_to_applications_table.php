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
            $table->longText('dockercompose_raw')->nullable();
            $table->longText('dockercompose')->nullable();
            $table->json('service_configurations')->nullable();

        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('dockercompose_raw');
            $table->dropColumn('dockercompose');
            $table->dropColumn('service_configurations');
        });
    }
};
