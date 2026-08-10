<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Clear dockerfile fields for applications not using dockerfile buildpack
        DB::table('applications')
            ->where('build_pack', '!=', 'dockerfile')
            ->update([
                'dockerfile' => null,
                'dockerfile_location' => null,
                'dockerfile_target_build' => null,
                'custom_healthcheck_found' => false,
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No rollback needed - we're cleaning up corrupt data
    }
};
