<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Image scanning has no instance switch and no vulnerability database mirror. Instances that ran the image
     * scanning migration before they were removed still have the columns.
     */
    public function up(): void
    {
        foreach (['is_image_scanning_allowed', 'image_scan_db_url'] as $column) {
            if (Schema::hasColumn('instance_settings', $column)) {
                Schema::table('instance_settings', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }

    public function down(): void
    {
        //
    }
};
