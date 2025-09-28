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
            // Check if the column exists before trying to drop it
            if (Schema::hasColumn('environment_variables', 'is_build_time')) {
                // Drop the is_build_time column
                // Note: The unique constraints that included is_build_time were tied to old foreign key columns
                // (application_id, service_id, database_id) which were removed in migration 2024_12_16_134437.
                // Those constraints should no longer exist in the database.
                $table->dropColumn('is_build_time');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('environment_variables', function (Blueprint $table) {
            // Re-add the is_build_time column
            if (! Schema::hasColumn('environment_variables', 'is_build_time')) {
                $table->boolean('is_build_time')->default(false)->after('value');
            }
        });
    }
};
