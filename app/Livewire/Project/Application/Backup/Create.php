<?php

namespace App\Livewire\Project\Application\Backup;

use App\Models\Application;
use App\Models\S3Storage;
use App\Models\ScheduledVolumeBackup;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Create extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public Application $application;

    public ?int $selectedVolumeId = null;

    public ?int $volumeId = null;

    public bool $volumeLocked = false;

    public string $frequency = 'daily';

    public bool $saveToS3 = false;

    public ?int $s3StorageId = null;

    public Collection $volumes;

    public Collection $definedS3s;

    protected function rules(): array
    {
        return [
            'volumeId' => ['required', 'integer'],
            'frequency' => ['required', 'string'],
            'saveToS3' => ['required', 'boolean'],
            's3StorageId' => ['nullable', 'integer'],
        ];
    }

    public function mount(): void
    {
        $this->authorize('view', $this->application);
        $this->volumes = $this->application->persistentStorages()->orderBy('name')->get();
        $this->definedS3s = S3Storage::ownedByCurrentTeam()->where('is_usable', true)->get();
        $this->s3StorageId = $this->definedS3s->first()?->id;
        $this->volumeLocked = $this->selectedVolumeId !== null;
        $this->volumeId = $this->selectedVolumeId ?? $this->volumes->first()?->id;
        $this->loadSelectedBackup();
    }

    public function updatedVolumeId(): void
    {
        $this->loadSelectedBackup();
    }

    public function submit(): void
    {
        $this->authorize('update', $this->application);
        $this->validate();

        $volume = $this->application->persistentStorages()->whereKey($this->volumeId)->first();
        if (! $volume) {
            $this->addError('volumeId', 'Select a volume owned by this application.');

            return;
        }

        if (! validate_cron_expression($this->frequency)) {
            $this->addError('frequency', 'The frequency must be a valid cron or human expression.');

            return;
        }

        if ($this->saveToS3 && ! $this->validS3StorageExists()) {
            $this->addError('s3StorageId', 'Select a usable S3 storage owned by your team.');

            return;
        }

        $backup = ScheduledVolumeBackup::query()->updateOrCreate(
            ['local_persistent_volume_id' => $volume->id],
            [
                'team_id' => currentTeam()->id,
                'frequency' => $this->frequency,
                'enabled' => true,
                'save_s3' => $this->saveToS3,
                's3_storage_id' => $this->saveToS3 ? $this->s3StorageId : null,
            ],
        );

        $this->dispatch('success', $backup->wasRecentlyCreated ? 'Scheduled volume backup created.' : 'Scheduled volume backup updated.');
        $this->dispatch('refreshVolumeBackups');
        $this->dispatch('close-modal');
    }

    public function render()
    {
        return view('livewire.project.application.backup.create');
    }

    private function loadSelectedBackup(): void
    {
        $volume = $this->volumes->firstWhere('id', $this->volumeId);
        if (! $volume) {
            return;
        }

        $backup = $volume->scheduledBackups()->first();
        if ($backup) {
            $this->frequency = $backup->frequency;
            $this->saveToS3 = $backup->save_s3;
            $this->s3StorageId = $backup->s3_storage_id ?? $this->definedS3s->first()?->id;
        }
    }

    private function validS3StorageExists(): bool
    {
        return $this->s3StorageId !== null
            && S3Storage::ownedByCurrentTeam()
                ->where('is_usable', true)
                ->whereKey($this->s3StorageId)
                ->exists();
    }
}
