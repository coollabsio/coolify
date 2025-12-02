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
        $postgresContainer = $this->database->uuid;
        $stanzaName = $this->database->getPgbackrestStanzaName();

        Log::info('Starting pgBackRest restore', [
            'database_id' => $this->database->id,
            'backup_label' => $this->backupLabel,
            'target_time' => $this->targetTime,
        ]);

        $this->updateRestoreStatus('running', 'Stopping PostgreSQL for restore...');

        try {
            $restoreCommand = $this->buildRestoreCommand($stanzaName);

            $this->updateRestoreStatus('running', 'Stopping PostgreSQL...');

            $this->stopPostgresql($server, $postgresContainer);

            $this->updateRestoreStatus('running', 'Clearing data directory...');

            $this->clearDataDirectory($server, $postgresContainer);

            $this->updateRestoreStatus('running', "Restoring from backup: {$this->backupLabel}...");

            $output = $this->executeRestore($server, $postgresContainer, $restoreCommand);

            Log::info('pgBackRest restore completed', [
                'database_id' => $this->database->id,
                'output' => $output,
            ]);

            if ($this->restartAfter) {
                $this->updateRestoreStatus('running', 'Restore complete. Restarting PostgreSQL...');
                Log::info('Restarting PostgreSQL after restore', ['database_id' => $this->database->id]);
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

    private function stopPostgresql(Server $server, string $container): void
    {
        $dataDir = '/var/lib/postgresql/data';
        $pidFile = "{$dataDir}/postmaster.pid";

        $stopCmd = "docker exec {$container} su postgres -c 'pg_ctl stop -D {$dataDir} -m fast -w -t 30' 2>&1 || true";
        instant_remote_process([$stopCmd], $server, false);

        sleep(2);

        $checkPidCmd = "docker exec {$container} test -f {$pidFile} && echo 'running' || echo 'stopped'";
        $result = instant_remote_process([$checkPidCmd], $server, false);

        if (str_contains($result ?? '', 'running')) {
            $stopImmediate = "docker exec {$container} su postgres -c 'pg_ctl stop -D {$dataDir} -m immediate -w -t 10' 2>&1 || true";
            instant_remote_process([$stopImmediate], $server, false);
            sleep(2);

            $result = instant_remote_process([$checkPidCmd], $server, false);
            if (str_contains($result ?? '', 'running')) {
                $killCmd = "docker exec {$container} sh -c 'pkill -9 postgres 2>/dev/null || true; rm -f {$pidFile}'";
                instant_remote_process([$killCmd], $server, false);
                sleep(1);
            }
        }
    }

    private function clearDataDirectory(Server $server, string $container): void
    {
        $dataDir = '/var/lib/postgresql/data';
        $clearCmd = "docker exec -u 0:0 {$container} sh -c 'rm -rf {$dataDir}/* {$dataDir}/.[!.]* 2>/dev/null; chown postgres:postgres {$dataDir}'";
        instant_remote_process([$clearCmd], $server, false);
    }

    private function executeRestore(Server $server, string $container, string $restoreCommand): string
    {
        $cmd = "docker exec {$container} su postgres -c 'pgbackrest {$restoreCommand}' 2>&1";

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
