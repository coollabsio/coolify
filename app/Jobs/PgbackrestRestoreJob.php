<?php

namespace App\Jobs;

use App\Actions\Database\StartPostgresql;
use App\Events\DatabaseStatusChanged;
use App\Models\Server;
use App\Models\StandalonePostgresql;
use App\Notifications\Database\PgbackrestRestoreFailed;
use App\Notifications\Database\PgbackrestRestoreSuccess;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PgbackrestRestoreJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 7200;

    public $tries = 1;

    public function __construct(
        public StandalonePostgresql $database,
        public ?string $backupLabel = null,
        public ?string $targetTime = null,
        public bool $restartAfter = true
    ) {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        $server = $this->database->destination->server;
        $containerName = $this->database->uuid;
        $stanzaName = $this->database->getPgbackrestStanzaName();

        Log::info('Starting pgBackRest restore', [
            'database_id' => $this->database->id,
            'backup_label' => $this->backupLabel,
            'target_time' => $this->targetTime,
        ]);

        $this->updateRestoreStatus('running', 'Preparing for restore...');

        try {
            $mounts = $this->getVolumeMounts($server);

            $this->updateRestoreStatus('running', 'Stopping PostgreSQL container...');
            $this->stopContainer($server, $containerName);

            $this->updateRestoreStatus('running', 'Clearing data directory...');
            $this->clearDataDirectory($server, $mounts);

            $this->updateRestoreStatus('running', "Restoring from backup: {$this->backupLabel}...");
            $restoreCommand = $this->buildRestoreCommand($stanzaName);
            $output = $this->executeRestoreWithTempContainer($server, $mounts, $restoreCommand);

            Log::info('pgBackRest restore completed', [
                'database_id' => $this->database->id,
                'output' => $output,
            ]);

            if ($this->restartAfter) {
                $this->updateRestoreStatus('running', 'Restore complete. Starting PostgreSQL...');
                Log::info('Starting PostgreSQL after restore', ['database_id' => $this->database->id]);
                StartPostgresql::run($this->database);
            } else {
                $this->database->update(['status' => 'exited']);
            }

            $this->updateRestoreStatus('success', 'Restore completed successfully.');

            $team = $this->database->team();
            $team?->notify(new PgbackrestRestoreSuccess($this->database, $this->backupLabel));

        } catch (\Throwable $e) {
            $errorMessage = $this->formatErrorMessage($e);

            Log::error('pgBackRest restore failed', [
                'database_id' => $this->database->id,
                'error' => $e->getMessage(),
            ]);

            $this->database->update(['status' => 'error']);
            $this->updateRestoreStatus('failed', $errorMessage);

            $team = $this->database->team();
            $team?->notify(new PgbackrestRestoreFailed($this->database, $e->getMessage(), $this->backupLabel));

            throw $e;
        }
    }

    private function stopContainer(Server $server, string $containerName): void
    {
        $stopCmd = "docker stop -t 30 {$containerName} 2>/dev/null || true";
        instant_remote_process([$stopCmd], $server, false);
        sleep(2);
    }

    private function getVolumeMounts(Server $server): array
    {
        $containerName = $this->database->uuid;
        $dataVolume = "postgres-data-{$containerName}";

        $inspectCmd = "docker inspect {$containerName} --format '{{range .Mounts}}{{if eq .Destination \"/etc/pgbackrest\"}}PGBACKREST_CONFIG={{.Source}}{{end}}{{if eq .Destination \"/var/lib/pgbackrest\"}}PGBACKREST_REPO={{.Source}}{{end}}{{end}}' 2>/dev/null || true";
        $output = instant_remote_process([$inspectCmd], $server, false);

        $pgbackrestConfig = '';
        $pgbackrestRepo = '';

        if (preg_match('/PGBACKREST_CONFIG=([^\s]+)/', $output ?? '', $matches)) {
            $pgbackrestConfig = $matches[1];
        }
        if (preg_match('/PGBACKREST_REPO=([^\s]+)/', $output ?? '', $matches)) {
            $pgbackrestRepo = $matches[1];
        }

        if (empty($pgbackrestConfig) || empty($pgbackrestRepo)) {
            $configDir = database_configuration_dir()."/{$containerName}";
            $defaultConfig = "{$configDir}/pgbackrest";
            $defaultRepo = "{$configDir}/pgbackrest-repo";

            $checkPathsCmd = "test -d {$defaultRepo} && echo 'EXISTS' || echo 'MISSING'";
            $pathCheck = instant_remote_process([$checkPathsCmd], $server, throwError: false);

            if (trim($pathCheck) === 'EXISTS') {
                $pgbackrestConfig = $pgbackrestConfig ?: $defaultConfig;
                $pgbackrestRepo = $pgbackrestRepo ?: $defaultRepo;
            } else {
                $devConfigDir = "/var/lib/docker/volumes/coolify_dev_coolify_data/_data/databases/{$containerName}";
                $devCheckCmd = "test -d {$devConfigDir}/pgbackrest-repo && echo 'EXISTS' || echo 'MISSING'";
                $devCheck = instant_remote_process([$devCheckCmd], $server, throwError: false);

                if (trim($devCheck) === 'EXISTS') {
                    $pgbackrestConfig = $pgbackrestConfig ?: "{$devConfigDir}/pgbackrest";
                    $pgbackrestRepo = $pgbackrestRepo ?: "{$devConfigDir}/pgbackrest-repo";
                } else {
                    $pgbackrestConfig = $pgbackrestConfig ?: $defaultConfig;
                    $pgbackrestRepo = $pgbackrestRepo ?: $defaultRepo;
                }
            }
        }

        return [
            'data_volume' => $dataVolume,
            'pgbackrest_config' => $pgbackrestConfig,
            'pgbackrest_repo' => $pgbackrestRepo,
        ];
    }

    private function clearDataDirectory(Server $server, array $mounts): void
    {
        $dataVolume = $mounts['data_volume'];

        $clearCmd = "docker run --rm -v {$dataVolume}:/var/lib/postgresql/data alpine sh -c 'rm -rf /var/lib/postgresql/data/* /var/lib/postgresql/data/.[!.]*'";
        instant_remote_process([$clearCmd], $server, false);
    }

    private function executeRestoreWithTempContainer(Server $server, array $mounts, string $restoreCommand): string
    {
        $image = $this->database->image;

        $cmd = 'docker run --rm '.
            "-v {$mounts['data_volume']}:/var/lib/postgresql/data ".
            "-v {$mounts['pgbackrest_config']}:/etc/pgbackrest ".
            "-v {$mounts['pgbackrest_repo']}:/var/lib/pgbackrest ".
            "{$image} sh -c '".
            'apk add --no-cache pgbackrest 2>/dev/null || (apt-get update && apt-get install -y pgbackrest) 2>/dev/null; '.
            'chown -R postgres:postgres /var/lib/postgresql/data /var/lib/pgbackrest /etc/pgbackrest 2>/dev/null; '.
            "su postgres -c \"pgbackrest {$restoreCommand}\"".
            "' 2>&1";

        $fullCmd = "set +e; {$cmd}; EXIT_CODE=\$?; echo \"PGBACKREST_EXIT_CODE:\${EXIT_CODE}\"; exit \$EXIT_CODE";

        $output = instant_remote_process([$fullCmd], $server, throwError: false);

        $exitCode = 0;
        if (preg_match('/PGBACKREST_EXIT_CODE:(\d+)/', $output ?? '', $matches)) {
            $exitCode = (int) $matches[1];
            $output = preg_replace('/PGBACKREST_EXIT_CODE:\d+\s*$/', '', $output ?? '');
        }

        if ($exitCode !== 0) {
            $errorOutput = trim($output ?? '');
            if (empty($errorOutput)) {
                $errorOutput = "pgBackRest restore failed with exit code {$exitCode}";
            }
            throw new \RuntimeException($errorOutput, $exitCode);
        }

        return $output ?? '';
    }

    private function updateRestoreStatus(string $status, string $message): void
    {
        $this->database->update([
            'pgbackrest_restore_status' => $status,
            'pgbackrest_restore_message' => $message,
            'status' => $status === 'running' ? 'restoring' : ($status === 'failed' ? 'error' : $this->database->status),
        ]);

        $this->broadcastStatusChange();
    }

    private function formatErrorMessage(\Throwable $e): string
    {
        $message = $e->getMessage();

        if (str_contains($message, 'FATAL')) {
            preg_match('/FATAL.*/', $message, $matches);
            if (! empty($matches)) {
                return "pgBackRest Error: {$matches[0]}";
            }
        }

        if (str_contains($message, 'ERROR')) {
            preg_match('/ERROR[^:]*:.*/', $message, $matches);
            if (! empty($matches)) {
                return "pgBackRest Error: {$matches[0]}";
            }
        }

        if (str_contains($message, 'archive_mode')) {
            return 'PostgreSQL is not configured for archiving. Please ensure pgBackRest is properly enabled and the database has been restarted.';
        }

        if (str_contains($message, 'stanza')) {
            return 'pgBackRest stanza not found. The backup repository may not be initialized. Try creating a backup first.';
        }

        if (str_contains($message, 'backup set')) {
            return "Backup '{$this->backupLabel}' not found in the repository. It may have been expired by retention policy.";
        }

        if (str_contains($message, 'permission')) {
            return 'Permission error during restore. The pgBackRest container may not have proper access to the data directory.';
        }

        return strlen($message) > 500 ? substr($message, 0, 500).'...' : $message;
    }

    private function buildRestoreCommand(string $stanzaName): string
    {
        $command = '--stanza='.escapeshellarg($stanzaName);

        if ($this->backupLabel) {
            $command .= ' --set='.escapeshellarg($this->backupLabel);
        }

        if ($this->targetTime) {
            $command .= ' --type=time --target='.escapeshellarg($this->targetTime);
        } else {
            $command .= ' --type=immediate';
        }

        $command .= ' --target-action=promote --delta --link-all restore';

        return $command;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('pgBackRest restore job failed', [
            'database_id' => $this->database->id,
            'error' => $exception->getMessage(),
        ]);

        $this->database->update([
            'status' => 'error',
            'pgbackrest_restore_status' => 'failed',
            'pgbackrest_restore_message' => $this->formatErrorMessage($exception),
        ]);

        $this->broadcastStatusChange();
    }

    private function broadcastStatusChange(): void
    {
        $team = $this->database->team();
        if ($team) {
            foreach ($team->members as $member) {
                DatabaseStatusChanged::dispatch($member->id);
            }
        }
    }
}
