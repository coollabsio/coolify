<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->boolean('is_image_scan_enabled')->default(false);
            $table->unsignedInteger('image_candidate_scans_today')->default(0);
            $table->date('image_candidate_scans_date')->nullable();
        });

        Schema::table('instance_settings', function (Blueprint $table) {
            $table->boolean('is_image_scanning_allowed')->default(true);
            $table->string('image_scan_db_url')->nullable();
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->boolean('scan_images_on_new_servers')->default(false);
        });

        Schema::table('application_settings', function (Blueprint $table) {
            $table->boolean('is_image_scan_enabled')->default(true);
            $table->boolean('image_builds_by_team')->nullable();
        });

        Schema::table('application_deployment_queues', function (Blueprint $table) {
            $table->jsonb('scan_gate_result')->nullable();
            $table->foreignId('scan_gate_bypassed_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('application_deployment_queues', function (Blueprint $table) {
            $table->dropConstrainedForeignId('scan_gate_bypassed_by');
            $table->dropColumn('scan_gate_result');
        });

        Schema::table('application_settings', function (Blueprint $table) {
            $table->dropColumn(['is_image_scan_enabled', 'image_builds_by_team']);
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('scan_images_on_new_servers');
        });

        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn(['is_image_scanning_allowed', 'image_scan_db_url']);
        });

        Schema::table('server_settings', function (Blueprint $table) {
            $table->dropColumn(['is_image_scan_enabled', 'image_candidate_scans_today', 'image_candidate_scans_date']);
        });
    }
};
