<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The unique key on (resource_type, resource_id) already serves every lookup, so the index that morphs() added on the
 * same columns only slows down writes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('image_scan_notification_states', function (Blueprint $table) {
            $table->dropIndex(['resource_type', 'resource_id']);
        });
    }

    public function down(): void
    {
        Schema::table('image_scan_notification_states', function (Blueprint $table) {
            $table->index(['resource_type', 'resource_id']);
        });
    }
};
