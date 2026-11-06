<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * No query filters audit events by level or by resource, so these indexes only cost disk space and write time.
     */
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table) {
            if (Schema::hasIndex('audit_events', 'audit_events_level_index')) {
                $table->dropIndex('audit_events_level_index');
            }
            if (Schema::hasIndex('audit_events', ['team_id', 'resource_type', 'resource_uuid', 'created_at'])) {
                $table->dropIndex(['team_id', 'resource_type', 'resource_uuid', 'created_at']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table) {
            $table->index('level');
            $table->index(['team_id', 'resource_type', 'resource_uuid', 'created_at']);
        });
    }
};
