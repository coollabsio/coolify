<?php

namespace App\Livewire\Project\Shared\Storages;

use App\Actions\Shared\DeleteScheduledVolumeBackup;
use App\Jobs\VolumeBackupJob;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\S3Storage;
use App\Models\ScheduledVolumeBackup;
use App\Models\Service;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

class VolumeBackups extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    public LocalPersistentVolume|LocalFileVolume $storage;

    public $resource;

    public ?ScheduledVolumeBackup $backup = null;

    public string $section = 'general';

    public string $frequency = 'daily';

    public bool $enabled = false;

    public bool $saveToS3 = false;

    public bool $disableLocalBackup = false;

    public bool $stopDuringBackup = false;

    public ?int $s3StorageId = null;

    public int $retentionAmountLocally = 7;

    public int $retentionDaysLocally = 0;

    public float $retentionMaxStorageLocally = 0;

    public int $retentionAmountS3 = 7;

    public int $retentionDaysS3 = 0;

    public float $retentionMaxStorageS3 = 0;

    public string $timezone = '';

    public int $timeout = ScheduledVolumeBackup::DEFAULT_TIMEOUT;

    public int $perPage = 10;

    public function updatedPerPage(): void
    {
        $this->perPage = max(1, min(100, $this->perPage));

        $this->resetPage();
    }

    public bool $delete_backup_s3 = false;

    public Collection $availableS3Storages;

    protected function rules(): array
    {
        return [
            'frequency' => ['required', 'string'],
            'enabled' => ['required', 'boolean'],
            'saveToS3' => ['required', 'boolean'],
            'disableLocalBackup' => ['required', 'boolean'],
            'stopDuringBackup' => ['required', 'boolean'],
            's3StorageId' => ['nullable', 'integer'],
            'retentionAmountLocally' => ['required', 'integer', 'min:0', 'max:10000'],
            'retentionDaysLocally' => ['required', 'integer', 'min:0'],
            'retentionMaxStorageLocally' => ['required', 'numeric', 'min:0'],
            'retentionAmountS3' => ['required', 'integer', 'min:0', 'max:10000'],
            'retentionDaysS3' => ['required', 'integer', 'min:0'],
            'retentionMaxStorageS3' => ['required', 'numeric', 'min:0'],
            'timeout' => ['required', 'integer', 'min:60', 'max:36000'],
        ];
    }

    public function mount(): void
    {
        $this->authorize('view', $this->resource);
        $this->availableS3Storages = S3Storage::ownedByCurrentTeam()
            ->where('is_usable', true)
            ->get();
        $this->backup = $this->storage->scheduledBackups()->first();
        $server = $this->backup?->server() ?? data_get($this->resource, 'destination.server');
        $this->timezone = data_get($server, 'settings.server_timezone', 'Instance timezone');

        if ($this->backup) {
            $this->frequency = $this->backup->frequency;
            $this->enabled = $this->backup->enabled;
            $this->saveToS3 = $this->backup->save_s3;
            $this->disableLocalBackup = $this->backup->disable_local_backup;
            $this->stopDuringBackup = $this->backup->stop_during_backup;
            $this->s3StorageId = $this->backup->s3_storage_id ?? $this->availableS3Storages->first()?->id;
            $this->retentionAmountLocally = $this->backup->retention_amount_locally;
            $this->retentionDaysLocally = $this->backup->retention_days_locally;
            $this->retentionMaxStorageLocally = $this->backup->retention_max_storage_locally;
            $this->retentionAmountS3 = $this->backup->retention_amount_s3;
            $this->retentionDaysS3 = $this->backup->retention_days_s3;
            $this->retentionMaxStorageS3 = $this->backup->retention_max_storage_s3;
            $this->timeout = $this->backup->timeout;
        } else {
            $this->s3StorageId = $this->availableS3Storages->first()?->id;
        }
    }

    public function save(): void
    {
        $this->authorize('update', $this->resource);

        if (! $this->validateSettings()) {
            return;
        }

        $this->backup = $this->persistBackup($this->enabled);
        $this->dispatch('success', 'Storage backup schedule saved.');
    }

    public function instantSave(): void
    {
        $this->save();
    }

    public function updatedS3StorageId(): void
    {
        $this->authorize('update', $this->resource);

        if (! $this->hasValidS3Storage()) {
            $this->addError('s3StorageId', 'Select a usable S3 storage owned by your team.');

            return;
        }

        $this->resetErrorBag('s3StorageId');
        $this->backup?->update(['s3_storage_id' => $this->s3StorageId]);
        $this->dispatch('success', 'S3 storage updated.');
    }

    public function toggleS3(): void
    {
        $this->authorize('update', $this->resource);

        if (! $this->saveToS3 && ! $this->hasValidS3Storage()) {
            $this->dispatch('error', 'Select a usable S3 storage before enabling S3 backups.');

            return;
        }

        $this->saveToS3 = ! $this->saveToS3;
        $this->disableLocalBackup = $this->saveToS3 && $this->disableLocalBackup;
        $this->backup?->update([
            'save_s3' => $this->saveToS3,
            'disable_local_backup' => $this->disableLocalBackup,
            's3_storage_id' => $this->s3StorageId,
        ]);
        $this->dispatch('success', $this->saveToS3 ? 'S3 backups enabled.' : 'S3 backups disabled.');
    }

    public function toggleEnabled(): void
    {
        $this->authorize('update', $this->resource);

        if (! $this->backup) {
            if (! $this->validateSettings()) {
                return;
            }

            $this->enabled = true;
            $this->backup = $this->persistBackup(true);
        } else {
            $this->enabled = ! $this->enabled;
            $this->backup->update(['enabled' => $this->enabled]);
        }

        $this->dispatch('success', $this->enabled ? 'Storage backups enabled.' : 'Storage backups disabled.');
    }

    public function backupNow(): Redirector|RedirectResponse|null
    {
        $this->authorize('update', $this->resource);

        if (! $this->backup) {
            if (! $this->validateSettings()) {
                return null;
            }

            $this->enabled = false;
            $this->backup = $this->persistBackup(false);
        }

        VolumeBackupJob::dispatch($this->backup);
        $this->dispatch('success', 'Storage backup queued.');

        return redirect()->route($this->routeName('executions'), $this->routeParameters());
    }

    public function delete(?string $password = null, array $selectedActions = []): bool|string
    {
        $this->authorize('update', $this->resource);

        if (! verifyPasswordConfirmation($password, $this)) {
            return 'The provided password is incorrect.';
        }

        if (! $this->backup) {
            return false;
        }

        try {
            DeleteScheduledVolumeBackup::run($this->backup);
            $this->backup = null;
            $this->dispatch('success', 'Storage backup schedule and archives deleted.');
            $this->redirectRoute($this->routeName('index'), $this->routeParameters(includeBackup: false), navigate: true);

            return true;
        } catch (Throwable $exception) {
            $this->dispatch('error', 'Could not delete the backup archives: '.$exception->getMessage());

            return false;
        }
    }

    public function cleanupFailed(): void
    {
        $this->authorize('update', $this->resource);

        $deletedCount = $this->backup?->executions()
            ->where('status', 'failed')
            ->where('stop_recovery_pending', false)
            ->where('s3_cleanup_pending', false)
            ->where(fn ($query) => $query
                ->whereNull('filename')
                ->orWhere('local_storage_deleted', true))
            ->delete() ?? 0;

        $this->dispatch(
            $deletedCount > 0 ? 'success' : 'info',
            $deletedCount > 0 ? 'Failed backup entries cleaned up.' : 'No safely removable failed backup entries found.',
        );
    }

    public function cleanupDeleted(): void
    {
        $this->authorize('update', $this->resource);

        $deletedCount = $this->backup?->executions()
            ->where('local_storage_deleted', true)
            ->where(fn ($query) => $query
                ->where('s3_storage_deleted', true)
                ->orWhereNull('s3_uploaded')
                ->orWhere('s3_uploaded', false))
            ->delete() ?? 0;

        $this->dispatch(
            $deletedCount > 0 ? 'success' : 'info',
            $deletedCount > 0 ? "Cleaned up {$deletedCount} deleted backup entries." : 'No deleted backup entries found.',
        );
    }

    public function deleteBackup(int $executionId, string $password, array $selectedActions = []): bool|string
    {
        $this->authorize('update', $this->resource);

        if (! verifyPasswordConfirmation($password, $this)) {
            return 'The provided password is incorrect.';
        }

        $execution = $this->backup?->executions()->whereKey($executionId)->first();
        if (! $execution) {
            $this->dispatch('error', 'Backup execution not found.');

            return false;
        }

        if ($execution->status === 'running' || $execution->stop_recovery_pending || $execution->s3_cleanup_pending) {
            $this->dispatch('error', 'Wait for the backup and recovery operations to finish before deleting it.');

            return false;
        }

        try {
            $server = $this->backup->server();
            if (! $execution->local_storage_deleted && filled($execution->filename)) {
                if (! $server) {
                    throw new \RuntimeException('The server is unavailable.');
                }

                deleteBackupsLocally($execution->filename, $server, throwError: true);
            }

            if ($this->delete_backup_s3 && $execution->s3_uploaded && ! $execution->s3_storage_deleted) {
                if (! $execution->s3) {
                    throw new \RuntimeException('The S3 storage is unavailable.');
                }

                deleteBackupsS3($execution->filename, $execution->s3);
            }

            $execution->delete();
            $this->delete_backup_s3 = false;
            $this->dispatch('success', 'Backup deleted.');

            return true;
        } catch (Throwable $exception) {
            $this->dispatch('error', 'Failed to delete backup: '.$exception->getMessage());

            return false;
        }
    }

    public function render()
    {
        $executions = $this->backup?->executions()->paginate($this->perPage);

        return view('livewire.project.shared.storages.volume-backups', [
            'executions' => $executions ?? collect(),
            'latestExecution' => $this->backup?->executions()->first(),
        ]);
    }

    private function validateSettings(): bool
    {
        $this->validate();

        if (! validate_cron_expression($this->frequency)) {
            $this->addError('frequency', 'The frequency must be a valid cron or human expression.');

            return false;
        }

        if ($this->saveToS3 && ! $this->hasValidS3Storage()) {
            $this->addError('s3StorageId', 'Select a usable S3 storage owned by your team.');

            return false;
        }

        $this->disableLocalBackup = $this->saveToS3 && $this->disableLocalBackup;

        return true;
    }

    private function persistBackup(bool $enabled): ScheduledVolumeBackup
    {
        return $this->storage->scheduledBackups()->updateOrCreate(
            [],
            [
                'team_id' => currentTeam()->id,
                'frequency' => $this->frequency,
                'enabled' => $enabled,
                'save_s3' => $this->saveToS3,
                'disable_local_backup' => $this->disableLocalBackup,
                'stop_during_backup' => $this->stopDuringBackup,
                's3_storage_id' => $this->hasValidS3Storage() ? $this->s3StorageId : $this->backup?->s3_storage_id,
                'retention_amount_locally' => $this->retentionAmountLocally,
                'retention_days_locally' => $this->retentionDaysLocally,
                'retention_max_storage_locally' => $this->retentionMaxStorageLocally,
                'retention_amount_s3' => $this->retentionAmountS3,
                'retention_days_s3' => $this->retentionDaysS3,
                'retention_max_storage_s3' => $this->retentionMaxStorageS3,
                'timeout' => $this->timeout,
            ],
        );
    }

    private function hasValidS3Storage(): bool
    {
        return $this->s3StorageId !== null
            && S3Storage::query()
                ->whereKey($this->s3StorageId)
                ->where('team_id', currentTeam()->id)
                ->where('is_usable', true)
                ->exists();
    }

    private function routeName(string $section): string
    {
        return $this->resource instanceof Service
            ? "project.service.volume-backups.{$section}"
            : "project.application.backup.{$section}";
    }

    private function routeParameters(bool $includeBackup = true): array
    {
        $parameters = [
            'project_uuid' => $this->resource->project()->uuid,
            'environment_uuid' => $this->resource->environment->uuid,
        ];
        $parameters[$this->resource instanceof Service ? 'service_uuid' : 'application_uuid'] = $this->resource->uuid;
        if ($includeBackup) {
            $parameters['backup_uuid'] = $this->backup?->uuid;
        }

        return $parameters;
    }
}
