<?php

namespace App\Jobs;

use App\Events\BackupCreated;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\Server;
use App\Models\ServiceDatabase;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Notifications\Database\BackupFailed;
use App\Notifications\Database\BackupSuccess;
use App\Notifications\Database\BackupSuccessWithS3Warning;
use App\Rules\SafeWebhookUrl;
use App\Support\BackupCompression;
use App\Support\ClickhouseBackupCommand;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class DatabaseBackupJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public ?Team $team = null;

    public function __construct(
        public ScheduledDatabaseBackup $backup,
        public ?ScheduledDatabaseBackupExecution $backup_log = null,
        public ?string $backup_log_uuid = null,
        public ?Server $server = null,
        public ?string $backup_location = null,
        public ?string $backup_output = null,
        public int $size = 0,
    ) {}

    public function handle(): void
    {
        $containerName = "coolify-helper-backup-{$this->backup->uuid}";

        try {
            $this->team = Team::find($this->backup->team_id);
            if (! $this->team) {
                $this->backup->delete();
                return;
            }
        } catch (Throwable $e) {
            $this->handleFailure($e);
            throw $e;
        } finally {
            $this->cleanupContainer($containerName);
            $this->finalizeExecution();
        }
    }

    private function handleFailure(Throwable $e): void
    {
        if ($this->backup_log) {
            $this->backup_log->update([
                'status' => 'failed',
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function cleanupContainer(string $containerName): void
    {
        if (!$this->server) return;

        try {
            instant_remote_process(
                command: "docker rm -f {$containerName}",
                server: $this->server,
                throwError: false
            );
        } catch (Throwable $ignored) {
        }
    }

    private function finalizeExecution(): void
    {
        if ($this->team) {
            BackupCreated::dispatch($this->team->id);
        }

        if ($this->backup_log) {
            $this->reconcileLogStatus();
        } elseif ($this->backup_log_uuid) {
            $this->reconcileOrphanedLog();
        }
    }

    private function reconcileLogStatus(): void
    {
        $updateData = ['finished_at' => Carbon::now()->toImmutable()];

        if ($this->backup_log->status === 'running') {
            if ($this->backup_location && $this->size > 0) {
                $updateData['status'] = 'success';
                $updateData['size'] = $this->size;
                $updateData['message'] = $this->backup_output ?? 'Backup completed successfully';
            } else {
                $updateData['status'] = 'failed';
                $updateData['message'] = 'Backup execution ended unexpectedly.';
            }
        }

        $this->backup_log->update($updateData);
    }

    private function reconcileOrphanedLog(): void
    {
        $log = ScheduledDatabaseBackupExecution::where('uuid', $this->backup_log_uuid)->first();

        if ($log && $log->status === 'running') {
            $log->update([
                'status' => 'failed',
                'message' => 'Backup execution finished but model reference was lost.',
                'finished_at' => Carbon::now()->toImmutable(),
            ]);
        }
    }
}
