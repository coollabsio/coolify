<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notification state moves from the scan rows to one row per resource. The scan columns of the two earlier
 * migrations of this unreleased feature are dropped here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('image_scan_notification_states', function (Blueprint $table) {
            $table->id();
            $table->morphs('resource');
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->jsonb('notified_ids')->nullable();
            $table->jsonb('notified_candidates')->nullable();
            $table->boolean('is_baseline')->default(true);
            $table->unsignedBigInteger('baseline_deployment_queue_id')->nullable();
            $table->timestamps();

            $table->unique(['resource_type', 'resource_id']);
        });

        Schema::table('image_scans', function (Blueprint $table) {
            $table->dropColumn(['notified_ids', 'notified_candidates']);
        });
    }

    public function down(): void
    {
        Schema::table('image_scans', function (Blueprint $table) {
            $table->jsonb('notified_ids')->nullable();
            $table->jsonb('notified_candidates')->nullable();
        });

        Schema::dropIfExists('image_scan_notification_states');
    }
};
