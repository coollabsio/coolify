<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['instance_settings', 'application_settings'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->boolean('is_smart_triggers_enabled')->default(true);
            });
        }

        foreach (['applications', 'application_previews'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('trigger_base_commit', 64)->nullable();
                $table->jsonb('trigger_set')->nullable();
            });
        }

        Schema::table('application_deployment_queues', function (Blueprint $table) {
            $table->jsonb('trigger_reason')->nullable();
            $table->unsignedInteger('skipped_count')->nullable();
            $table->string('first_skipped_commit', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('application_deployment_queues', function (Blueprint $table) {
            $table->dropColumn(['trigger_reason', 'skipped_count', 'first_skipped_commit']);
        });

        foreach (['applications', 'application_previews'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn(['trigger_base_commit', 'trigger_set']);
            });
        }

        foreach (['instance_settings', 'application_settings'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('is_smart_triggers_enabled');
            });
        }
    }
};
