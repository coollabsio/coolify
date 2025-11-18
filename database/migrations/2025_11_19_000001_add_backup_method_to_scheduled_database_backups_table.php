<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_database_backups', function (Blueprint $table) {
            // Add backup method selection (pg_dump or pgbackrest)
            $table->enum('backup_method', ['pg_dump', 'pgbackrest'])
                ->default('pg_dump')
                ->after('frequency')
                ->comment('Backup method: pg_dump (default) or pgbackrest');

            // Add backup type for pgbackrest (full, differential, incremental)
            $table->enum('backup_type', ['full', 'diff', 'incr'])
                ->nullable()
                ->after('backup_method')
                ->comment('pgBackRest backup type: full, diff (differential), or incr (incremental)');

            // Enable point-in-time recovery (requires WAL archiving)
            $table->boolean('enable_pitr')
                ->default(false)
                ->after('backup_type')
                ->comment('Enable point-in-time recovery with WAL archiving');

            // Store pgbackrest-specific configuration as JSON
            $table->json('pgbackrest_config')
                ->nullable()
                ->after('enable_pitr')
                ->comment('pgBackRest configuration overrides (JSON)');

            // Track stanza initialization status
            $table->boolean('pgbackrest_stanza_created')
                ->default(false)
                ->after('pgbackrest_config')
                ->comment('Whether pgBackRest stanza has been initialized');

            // Store stanza name for reference
            $table->string('pgbackrest_stanza_name')
                ->nullable()
                ->after('pgbackrest_stanza_created')
                ->comment('pgBackRest stanza name');
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_database_backups', function (Blueprint $table) {
            $table->dropColumn([
                'backup_method',
                'backup_type',
                'enable_pitr',
                'pgbackrest_config',
                'pgbackrest_stanza_created',
                'pgbackrest_stanza_name',
            ]);
        });
    }
};
