<?php

namespace App\Jobs {
    if (! function_exists(__NAMESPACE__.'\\instant_remote_process')) {
        function instant_remote_process(array $commands, ...$args): string
        {
            $GLOBALS['database_backup_job_captured_commands'] = $commands;

            return '';
        }
    }
}

namespace {
    use App\Jobs\DatabaseBackupJob;
    use App\Models\ScheduledDatabaseBackup;
    use App\Models\Server;
    use App\Models\StandalonePostgresql;

    function buildPostgresBackupJob(bool $dumpAll, string $containerName): DatabaseBackupJob
    {
        $backup = new ScheduledDatabaseBackup;
        $backup->dump_all = $dumpAll;
        $backup->timeout = 3600;

        $job = new DatabaseBackupJob($backup);
        $job->database = new StandalonePostgresql([
            'postgres_user' => 'coolify',
        ]);
        $job->server = new Server([
            'ip' => '127.0.0.1',
        ]);
        $job->backup_dir = '/tmp/coolify-backups';
        $job->backup_location = '/tmp/coolify-backups/pg-backup-file.dmp';
        $job->container_name = $containerName;
        $job->postgres_password = 'secret';

        return $job;
    }

    function invokeBackupStandalonePostgresql(DatabaseBackupJob $job, string $database): void
    {
        $invoker = Closure::bind(function (string $db): void {
            $this->backup_standalone_postgresql($db);
        }, $job, DatabaseBackupJob::class);

        $invoker($database);
    }

    test('coolify-db never uses pg_dumpall even if dump_all is enabled', function () {
        unset($GLOBALS['database_backup_job_captured_commands']);

        $job = buildPostgresBackupJob(dumpAll: true, containerName: 'coolify-db');
        invokeBackupStandalonePostgresql($job, 'coolify');

        $commands = $GLOBALS['database_backup_job_captured_commands'] ?? [];
        $backupCommand = collect($commands)->last();

        expect($backupCommand)->toContain('pg_dump --format=custom --no-acl --no-owner')
            ->and($backupCommand)->not->toContain('pg_dumpall');
    });

    test('non-coolify postgres uses pg_dumpall when dump_all is enabled', function () {
        unset($GLOBALS['database_backup_job_captured_commands']);

        $job = buildPostgresBackupJob(dumpAll: true, containerName: 'postgres-app-123');
        invokeBackupStandalonePostgresql($job, 'app');

        $commands = $GLOBALS['database_backup_job_captured_commands'] ?? [];
        $backupCommand = collect($commands)->last();

        expect($backupCommand)->toContain('pg_dumpall --username')
            ->and($backupCommand)->not->toContain('pg_dump --format=custom');
    });
}
