<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables and columns of coolify.json: the link of an environment to its file, the revisions of the file,
 * the stable key of each managed resource, and how the file sets each variable.
 */
return new class extends Migration
{
    private const RESOURCE_TABLES = [
        'applications', 'services', 'standalone_postgresqls', 'standalone_mysqls', 'standalone_mariadbs',
        'standalone_mongodbs', 'standalone_redis', 'standalone_keydbs', 'standalone_dragonflies',
        'standalone_clickhouses', 'standalone_sqlites',
    ];

    public function up(): void
    {
        foreach (self::RESOURCE_TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->string('config_key', 63)->nullable();
                $blueprint->unique(['environment_id', 'config_key']);
            });
        }

        Schema::table('environment_variables', function (Blueprint $table) {
            $table->json('config_meta')->nullable();
        });

        Schema::table('services', function (Blueprint $table) {
            $table->json('config_meta')->nullable();
        });

        Schema::table('application_settings', function (Blueprint $table) {
            $table->boolean('has_unlinked_config_file')->default(false);
        });

        Schema::create('environment_config_links', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('environment_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('mode', 16);
            $table->nullableMorphs('source');
            $table->foreignId('private_key_id')->nullable()->constrained()->nullOnDelete();
            $table->string('repository')->nullable();
            $table->string('branch')->nullable();
            $table->string('file_path')->default('coolify.json');
            $table->foreignId('read_server_id')->nullable()->constrained('servers')->nullOnDelete();
            $table->text('webhook_secret')->nullable();
            $table->boolean('auto_apply')->default(true);
            $table->timestamp('suspended_at')->nullable();
            $table->foreignId('suspended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('pending_commit_sha')->nullable();
            $table->json('pending_held_deployments')->nullable();
            $table->timestamp('pending_pushed_at')->nullable();
            $table->unsignedBigInteger('last_applied_revision_id')->nullable();
            $table->timestamps();
        });

        Schema::create('environment_config_revisions', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('link_id')->constrained('environment_config_links')->cascadeOnDelete();
            $table->string('trigger', 16);
            $table->string('commit_sha')->nullable();
            $table->string('file_hash', 64)->nullable();
            $table->longText('file_contents')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('state_hash', 64)->nullable();
            $table->unsignedSmallInteger('format_version')->nullable();
            $table->json('plan')->nullable();
            $table->string('status', 24)->index();
            $table->text('error')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->json('delete_data')->nullable();
            $table->json('held_deployments')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('environment_config_revisions');
        Schema::dropIfExists('environment_config_links');

        Schema::table('application_settings', function (Blueprint $table) {
            $table->dropColumn('has_unlinked_config_file');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('config_meta');
        });

        Schema::table('environment_variables', function (Blueprint $table) {
            $table->dropColumn('config_meta');
        });

        foreach (self::RESOURCE_TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropUnique(['environment_id', 'config_key']);
                $blueprint->dropColumn('config_key');
            });
        }
    }
};
