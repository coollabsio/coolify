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
        Schema::table('service_applications', function (Blueprint $table) {
            $table->boolean('is_migrated')->default(false);
        });
        Schema::table('service_databases', function (Blueprint $table) {
            $table->boolean('is_migrated')->default(false);
            $table->string('custom_type')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_applications', function (Blueprint $table) {
            $table->dropColumn('is_migrated');
        });
        Schema::table('service_databases', function (Blueprint $table) {
            $table->dropColumn('is_migrated');
            $table->dropColumn('custom_type');
        });
    }
};
