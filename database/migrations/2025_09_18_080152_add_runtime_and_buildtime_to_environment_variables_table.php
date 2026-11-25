<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('environment_variables', function (Blueprint $table) {
            // Add new boolean fields with defaults
            $table->boolean('is_runtime')->default(true)->after('is_buildtime_only');
            $table->boolean('is_buildtime')->default(true)->after('is_runtime');
        });

        // Migrate existing data from is_buildtime_only to new fields
        DB::table('environment_variables')
            ->where('is_buildtime_only', true)
            ->update([
                'is_runtime' => false,
                'is_buildtime' => true,
            ]);

        DB::table('environment_variables')
            ->where('is_buildtime_only', false)
            ->update([
                'is_runtime' => true,
                'is_buildtime' => true,
            ]);

        // Remove the old is_buildtime_only column
        Schema::table('environment_variables', function (Blueprint $table) {
            $table->dropColumn('is_buildtime_only');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('environment_variables', function (Blueprint $table) {
            // Re-add the is_buildtime_only column
            $table->boolean('is_buildtime_only')->default(false)->after('is_preview');
        });

        // Restore data to is_buildtime_only based on new fields
        DB::table('environment_variables')
            ->where('is_runtime', false)
            ->where('is_buildtime', true)
            ->update(['is_buildtime_only' => true]);

        DB::table('environment_variables')
            ->where('is_runtime', true)
            ->update(['is_buildtime_only' => false]);

        // Remove new columns
        Schema::table('environment_variables', function (Blueprint $table) {
            $table->dropColumn(['is_runtime', 'is_buildtime']);
        });
    }
};
