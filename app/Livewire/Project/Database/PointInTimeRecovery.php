<?php

namespace App\Livewire\Project\Database;

use App\Actions\Database\RestartDatabase;
use App\Actions\Database\ValidatePostgresqlWalGImage;
use App\Events\ServiceStatusChanged;
use App\Jobs\PostgresqlWalBaseBackupJob;
use App\Jobs\PostgresqlWalHealthCheckJob;
use App\Jobs\PostgresqlWalRestoreJob;
use App\Models\PostgresqlWalBackupConfiguration;
use App\Models\S3Storage;
use App\Models\StandalonePostgresql;
use App\Support\ValidationPatterns;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

class PointInTimeRecovery extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public StandalonePostgresql $database;

    #[Locked]
    public PostgresqlWalBackupConfiguration $configuration;

    public array $s3Storages = [];

    public ?string $s3StorageUuid = null;

    public string $baseBackupFrequency = '0 3 * * *';

    public int $archiveTimeoutSeconds = 60;

    public string $walLevel = 'replica';

    public int $retentionFullBackups = 7;

    public int $timeout = 3600;

    public bool $imageReady = false;

    public bool $storageAttached = false;

    public ?string $restoreTargetTime = null;

    public string $restoreName = '';

    public ?string $restoreDescription = null;

    protected function rules(): array
    {
        return [
            's3StorageUuid' => ['required', 'string'],
            'baseBackupFrequency' => ['required', 'string', 'max:255'],
            'archiveTimeoutSeconds' => ['required', 'integer', 'min:1', 'max:86400'],
            'walLevel' => ['required', 'string', 'in:replica,logical'],
            'retentionFullBackups' => ['required', 'integer', 'min:1', 'max:1000'],
            'timeout' => ['required', 'integer', 'min:60', 'max:36000'],
        ];
    }

    public function mount(?StandalonePostgresql $database = null): void
    {
        if (! $database) {
            $project = currentTeam()
                ->projects()
                ->where('uuid', request()->route('project_uuid'))
                ->firstOrFail();
            $environment = $project->environments()
                ->where('uuid', request()->route('environment_uuid'))
                ->firstOrFail();
            $resolvedDatabase = $environment->databases()
                ->where('uuid', request()->route('database_uuid'))
                ->firstOrFail();

            abort_unless($resolvedDatabase instanceof StandalonePostgresql, 404);
            $database = $resolvedDatabase;
        }

        $this->authorize('view', $database);
        $configuration = $database->walBackupConfiguration()->first();
        abort_unless($configuration, 404);

        $this->database = $database;
        $this->configuration = $configuration;
        $this->syncState();
    }

    public function save(): void
    {
        $this->authorize('manageBackups', $this->database);
        $this->persistSettings();
        $this->dispatch('success', 'Point-in-time recovery settings saved.');
    }

    public function applyAndRestart(): void
    {
        $this->authorize('manageBackups', $this->database);
        $this->persistSettings();
        $this->configuration->update(['status' => 'pending_restart']);

        $activity = RestartDatabase::run($this->database);
        if (! is_object($activity) || ! isset($activity->id)) {
            $this->dispatch('error', (string) $activity);

            return;
        }

        $this->js("window.dispatchEvent(new CustomEvent('startdatabase'))");
        $this->dispatch('activityMonitor', $activity->id, ServiceStatusChanged::class);
        $this->dispatch('success', 'Point-in-time recovery settings are being applied.');
    }

    public function runBaseBackup(): void
    {
        $this->authorize('manageBackups', $this->database);
        $this->configuration->refresh();

        if (
            ! $this->configuration->enabled
            || ! in_array($this->configuration->status, ['healthy', 'warning'], true)
            || ! $this->configuration->hasVerifiedArchivingHealth()
        ) {
            throw ValidationException::withMessages([
                'baseBackup' => 'Apply the configuration and wait for a healthy archive check before starting a base backup.',
            ]);
        }

        PostgresqlWalBaseBackupJob::dispatch($this->configuration, retryWhenBusy: true);
        $this->dispatch('success', 'WAL-G base backup queued and will retry if the repository is busy.');
    }

    public function runHealthCheck(): void
    {
        $this->authorize('manageBackups', $this->database);
        PostgresqlWalHealthCheckJob::dispatch($this->configuration);
        $this->dispatch('success', 'WAL archive health check queued.');
    }

    public function restore(): void
    {
        $this->authorize('manageBackups', $this->database);
        $this->validate([
            'restoreTargetTime' => ['required', 'string'],
            'restoreName' => ValidationPatterns::nameRules(),
            'restoreDescription' => ValidationPatterns::descriptionRules(required: false),
        ]);

        if (! preg_match('/(?:Z|\+00:00)\z/', $this->restoreTargetTime)) {
            throw ValidationException::withMessages([
                'restoreTargetTime' => 'The restore target must include an explicit UTC offset (Z or +00:00).',
            ]);
        }

        try {
            $targetTime = CarbonImmutable::parse($this->restoreTargetTime)->utc();
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'restoreTargetTime' => 'The restore target must be a valid UTC timestamp.',
            ]);
        }

        if ($targetTime->isFuture()) {
            throw ValidationException::withMessages([
                'restoreTargetTime' => 'The restore target time cannot be in the future.',
            ]);
        }

        PostgresqlWalRestoreJob::dispatch(
            $this->configuration,
            $targetTime,
            $this->restoreName,
            $this->restoreDescription,
        );
        $this->reset('restoreTargetTime', 'restoreName', 'restoreDescription');
        $this->dispatch('success', 'Point-in-time restore queued.');
    }

    public function render(): View
    {
        $this->configuration->refresh();

        return view('livewire.project.database.point-in-time-recovery', [
            'executions' => $this->configuration->executions()
                ->with('restoredDatabase:id,uuid,name')
                ->limit(10)
                ->get(),
        ]);
    }

    private function persistSettings(): void
    {
        $this->validate();

        if (! validate_cron_expression($this->baseBackupFrequency)) {
            throw ValidationException::withMessages([
                'baseBackupFrequency' => 'The base backup frequency must be a valid cron or human expression.',
            ]);
        }

        $this->configuration->refresh();
        $storage = $this->resolveStorage();
        $wasDetached = $this->configuration->s3_storage_id === null;
        $this->configuration->fill([
            's3_storage_id' => $storage->id,
            'base_backup_frequency' => $this->baseBackupFrequency,
            'archive_timeout_seconds' => $this->archiveTimeoutSeconds,
            'wal_level' => $this->walLevel,
            'retention_full_backups' => $this->retentionFullBackups,
            'timeout' => $this->timeout,
        ]);

        if ($wasDetached) {
            $this->configuration->enabled = true;
            $this->configuration->last_base_backup_at = null;
            $this->configuration->last_successful_base_backup_at = null;
        }
        if ($this->configuration->isDirty()) {
            $this->configuration->status = 'pending_restart';
            $this->configuration->last_health_message = $wasDetached
                ? 'S3 storage reattached; restart PostgreSQL to apply WAL archiving.'
                : 'Point-in-time recovery settings changed; restart PostgreSQL to apply them.';
            $this->configuration->save();
        }

        $this->syncState();
    }

    private function resolveStorage(): S3Storage
    {
        if ($this->configuration->s3_storage_id !== null) {
            $currentStorage = S3Storage::ownedByCurrentTeam(['uuid', 'is_usable'])
                ->whereKey($this->configuration->s3_storage_id)
                ->first();
            if (! $currentStorage || $currentStorage->uuid !== $this->s3StorageUuid) {
                throw ValidationException::withMessages([
                    's3StorageUuid' => 'Active PITR storage cannot be changed. Storage migration is not supported.',
                ]);
            }

            return $currentStorage;
        }

        $storage = S3Storage::ownedByCurrentTeam(['uuid', 'is_usable'])
            ->where('uuid', $this->s3StorageUuid)
            ->where('is_usable', true)
            ->first();
        if (! $storage) {
            throw ValidationException::withMessages([
                's3StorageUuid' => 'Select a usable S3 storage owned by the current team.',
            ]);
        }

        return $storage;
    }

    private function syncState(): void
    {
        $this->configuration->refresh();
        $this->s3StorageUuid = $this->configuration->s3_storage_id
            ? S3Storage::ownedByCurrentTeam(['uuid'])->whereKey($this->configuration->s3_storage_id)->value('uuid')
            : null;
        $this->baseBackupFrequency = $this->configuration->base_backup_frequency;
        $this->archiveTimeoutSeconds = $this->configuration->archive_timeout_seconds;
        $this->walLevel = $this->configuration->wal_level;
        $this->retentionFullBackups = $this->configuration->retention_full_backups;
        $this->timeout = $this->configuration->timeout;
        $this->storageAttached = $this->configuration->s3_storage_id !== null;
        $this->imageReady = $this->isImageReady();
        $this->s3Storages = $this->availableS3Storages()->all();
    }

    /**
     * @return Collection<int, array{uuid: string, name: string, is_usable: bool}>
     */
    private function availableS3Storages(): Collection
    {
        return S3Storage::ownedByCurrentTeam(['uuid', 'name', 'is_usable'])
            ->when(
                $this->configuration->s3_storage_id,
                fn ($query) => $query->where(function ($storageQuery): void {
                    $storageQuery
                        ->where('is_usable', true)
                        ->orWhere((new S3Storage)->getKeyName(), $this->configuration->s3_storage_id);
                }),
                fn ($query) => $query->where('is_usable', true),
            )
            ->get()
            ->map(fn (S3Storage $storage): array => [
                'uuid' => $storage->uuid,
                'name' => $storage->name,
                'is_usable' => $storage->isUsable(),
            ]);
    }

    private function isImageReady(): bool
    {
        try {
            ValidatePostgresqlWalGImage::run(
                $this->database->image,
                $this->configuration->postgres_major_version,
            );

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
