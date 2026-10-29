<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, array{0: string, 1: bool}> notification settings table => [alert column, default] */
    private const NOTIFICATION_COLUMNS = [
        'email_notification_settings' => ['image_vulnerabilities_email_notifications', true],
        'discord_notification_settings' => ['image_vulnerabilities_discord_notifications', false],
        'telegram_notification_settings' => ['image_vulnerabilities_telegram_notifications', false],
        'slack_notification_settings' => ['image_vulnerabilities_slack_notifications', false],
        'pushover_notification_settings' => ['image_vulnerabilities_pushover_notifications', false],
        'webhook_notification_settings' => ['image_vulnerabilities_webhook_notifications', false],
    ];

    public function up(): void
    {
        Schema::create('image_scan_policies', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('scope_type')->nullable();
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('gate_mode')->nullable();
            $table->string('on_scan_error')->nullable();
            $table->timestamps();

            $table->unique(['scope_type', 'scope_id']);
        });

        DB::table('image_scan_policies')->insert([
            'uuid' => new_public_id(),
            'scope_type' => null,
            'scope_id' => null,
            'gate_mode' => 'off',
            'on_scan_error' => 'deploy',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::create('image_scan_ignores', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->nullableMorphs('resource');
            $table->string('vulnerability_id', 64)->nullable();
            $table->string('package', 214)->nullable();
            $table->string('reason', 500);
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['team_id', 'resource_type', 'resource_id']);
        });

        Schema::create('image_sboms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('image_id', 80);
            $table->string('platform', 32);
            $table->jsonb('packages');
            $table->boolean('packages_truncated')->default(false);
            $table->timestamp('last_used_at');

            $table->unique(['server_id', 'image_id']);
        });

        Schema::create('image_scans', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('image_sbom_id')->nullable()->constrained('image_sboms')->nullOnDelete();
            $table->string('image_id', 80);
            $table->string('repo_digest', 80)->nullable();
            $table->morphs('resource');
            $table->string('image_ref', 512);
            $table->foreignId('deployment_queue_id')->nullable()->constrained('application_deployment_queues')->nullOnDelete();
            $table->string('status');
            $table->string('error_code')->nullable();
            $table->jsonb('error_context')->nullable();
            $table->jsonb('summary')->nullable();
            $table->jsonb('findings')->nullable();
            $table->jsonb('candidates')->nullable();
            $table->string('base_image_ref', 512)->nullable();
            $table->boolean('builds_image')->default(false);
            $table->boolean('is_baseline')->default(true);
            $table->timestamp('grype_db_built_at')->nullable();
            $table->timestamp('scanned_at')->nullable();
            $table->timestamps();

            $table->unique(['resource_type', 'resource_id', 'image_id']);
            $table->index(['server_id', 'image_id']);
        });

        Schema::create('image_scan_notification_states', function (Blueprint $table) {
            $table->id();
            // No morphs(): its index would repeat the unique key below.
            $table->string('resource_type');
            $table->unsignedBigInteger('resource_id');
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->jsonb('notified_ids')->nullable();
            $table->jsonb('notified_candidates')->nullable();
            $table->jsonb('digest_ids')->nullable();
            $table->boolean('is_baseline')->default(true);
            $table->boolean('check_reported')->default(false);
            $table->unsignedSmallInteger('held_digests')->default(0);
            $table->unsignedBigInteger('baseline_deployment_queue_id')->nullable();
            $table->timestamps();

            $table->unique(['resource_type', 'resource_id']);
        });

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

        Schema::table('applications', function (Blueprint $table) {
            // Security updates of a Compose app: {service: {from, to}}; applied while the compose file still names `from`.
            $table->json('docker_compose_image_overrides')->nullable();
        });

        Schema::table('application_deployment_queues', function (Blueprint $table) {
            $table->jsonb('scan_gate_result')->nullable();
            $table->foreignId('scan_gate_bypassed_by')->nullable()->constrained('users')->nullOnDelete();
        });

        foreach (self::NOTIFICATION_COLUMNS as $tableName => [$column, $default]) {
            Schema::table($tableName, function (Blueprint $table) use ($column, $default) {
                $table->boolean($column)->default($default);
            });
        }

        Schema::table('telegram_notification_settings', function (Blueprint $table) {
            $table->text('telegram_notifications_image_vulnerabilities_thread_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('telegram_notification_settings', function (Blueprint $table) {
            $table->dropColumn('telegram_notifications_image_vulnerabilities_thread_id');
        });

        foreach (self::NOTIFICATION_COLUMNS as $tableName => [$column]) {
            Schema::table($tableName, function (Blueprint $table) use ($column) {
                $table->dropColumn($column);
            });
        }

        Schema::table('application_deployment_queues', function (Blueprint $table) {
            $table->dropConstrainedForeignId('scan_gate_bypassed_by');
            $table->dropColumn('scan_gate_result');
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('docker_compose_image_overrides');
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

        Schema::dropIfExists('image_scan_notification_states');
        Schema::dropIfExists('image_scans');
        Schema::dropIfExists('image_sboms');
        Schema::dropIfExists('image_scan_ignores');
        Schema::dropIfExists('image_scan_policies');
    }
};
