<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('scheduled_database_backups', function (Blueprint $table) {
            // Add pgBackRest-specific configuration
            $table->boolean('use_pgbackrest')->default(false)->after('save_s3');
            $table->string('backup_engine')->default('pg_dump')->after('use_pgbackrest'); // 'pg_dump' or 'pgbackrest'
            $table->json('pgbackrest_config')->nullable()->after('backup_engine');
            
            // Add index for better query performance
            $table->index('backup_engine');
        });

        Schema::table('scheduled_database_backup_executions', function (Blueprint $table) {
            // Store backup type for pgBackRest
            $table->string('backup_type')->nullable()->after('filename'); // 'full', 'diff', 'incr'
            $table->bigInteger('database_size')->nullable()->after('size'); // Original database size
            
            // Add index
            $table->index('backup_type');
        });
        
        // Add configuration table for pgBackRest settings
        Schema::create('pgbackrest_configurations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('database_id')->constrained('standalone_postgresqls')->onDelete('cascade');
            $table->string('stanza_name');
            $table->boolean('is_configured')->default(false);
            $table->boolean('wal_archiving_enabled')->default(false);
            $table->timestamp('last_full_backup_at')->nullable();
            $table->timestamp('last_diff_backup_at')->nullable();
            $table->timestamp('last_incr_backup_at')->nullable();
            $table->json('configuration')->nullable(); // Store pgbackrest.conf as JSON
            $table->json('stanza_info')->nullable(); // Store stanza info
            $table->timestamps();
            
            $table->index('database_id');
            $table->index('is_configured');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scheduled_database_backups', function (Blueprint $table) {
            $table->dropIndex(['backup_engine']);
            $table->dropColumn(['use_pgbackrest', 'backup_engine', 'pgbackrest_config']);
        });

        Schema::table('scheduled_database_backup_executions', function (Blueprint $table) {
            $table->dropIndex(['backup_type']);
            $table->dropColumn(['backup_type', 'database_size']);
        });
        
        Schema::dropIfExists('pgbackrest_configurations');
    }
};