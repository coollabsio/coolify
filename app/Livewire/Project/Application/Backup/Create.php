<?php

namespace App\Livewire\Project\Application\Backup;

use App\Models\Application;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Create extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public Application $application;

    public ?string $selectedTargetKey = null;

    public ?string $targetKey = null;

    public bool $targetLocked = false;

    public string $frequency = 'daily';

    public Collection $targets;

    protected function rules(): array
    {
        return [
            'targetKey' => ['required', 'string', 'regex:/^(volume|directory):[1-9][0-9]*$/'],
            'frequency' => ['required', 'string'],
        ];
    }

    public function mount(): void
    {
        $this->authorize('view', $this->application);
        $this->targetLocked = $this->selectedTargetKey !== null;
        $this->targetKey = $this->selectedTargetKey;

        // When opened from a volume row the target is fixed — skip listing every volume/directory.
        if ($this->targetLocked && is_string($this->selectedTargetKey)) {
            $target = $this->selectedTarget();
            if ($target instanceof LocalPersistentVolume) {
                $this->targets = collect([[
                    'key' => 'volume:'.$target->id,
                    'type' => 'Volume',
                    'name' => $target->name,
                ]]);
            } elseif ($target instanceof LocalFileVolume) {
                $this->targets = collect([[
                    'key' => 'directory:'.$target->id,
                    'type' => 'Directory',
                    'name' => $target->fs_path,
                ]]);
            } else {
                $this->targets = collect();
            }
            $this->loadSelectedBackup();

            return;
        }

        $volumes = $this->application->persistentStorages()
            ->orderBy('name')
            ->get()
            ->map(fn (LocalPersistentVolume $volume): array => [
                'key' => 'volume:'.$volume->id,
                'type' => 'Volume',
                'name' => $volume->name,
            ]);
        $directories = $this->application->fileStorages()
            ->where('is_directory', true)
            ->where('is_host_file', false)
            ->orderBy('fs_path')
            ->get()
            ->map(fn (LocalFileVolume $directory): array => [
                'key' => 'directory:'.$directory->id,
                'type' => 'Directory',
                'name' => $directory->fs_path,
            ]);
        $this->targets = collect($volumes->concat($directories)->all())->values();
        $this->targetKey = $this->selectedTargetKey ?? data_get($this->targets->first(), 'key');
        $this->loadSelectedBackup();
    }

    public function updatedTargetKey(): void
    {
        $this->loadSelectedBackup();
    }

    public function submit(): void
    {
        $this->authorize('update', $this->application);
        $this->validate();

        $target = $this->selectedTarget();
        if (! $target) {
            $this->addError('targetKey', 'Select a volume or directory owned by this application.');

            return;
        }

        if (! validate_cron_expression($this->frequency)) {
            $this->addError('frequency', 'The frequency must be a valid cron or human expression.');

            return;
        }

        try {
            $backup = $target->scheduledBackups()->updateOrCreate(
                [],
                [
                    'team_id' => currentTeam()->id,
                    'frequency' => $this->frequency,
                    'enabled' => true,
                ],
            );

            $this->dispatch('success', $backup->wasRecentlyCreated ? 'Scheduled storage backup created.' : 'Scheduled storage backup updated.');
            redirectRoute($this, 'project.application.backup.show', [
                'project_uuid' => $this->application->project()->uuid,
                'environment_uuid' => $this->application->environment->uuid,
                'application_uuid' => $this->application->uuid,
                'backup_uuid' => $backup->uuid,
            ]);
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.application.backup.create');
    }

    private function loadSelectedBackup(): void
    {
        $target = $this->selectedTarget();
        if (! $target) {
            return;
        }

        $backup = $target->scheduledBackups()->first();
        if ($backup) {
            $this->frequency = $backup->frequency;
        }
    }

    private function selectedTarget(): LocalPersistentVolume|LocalFileVolume|null
    {
        [$type, $id] = array_pad(explode(':', (string) $this->targetKey, 2), 2, null);
        if (! ctype_digit((string) $id)) {
            return null;
        }

        return match ($type) {
            'volume' => $this->application->persistentStorages()->whereKey((int) $id)->first(),
            'directory' => $this->application->fileStorages()
                ->whereKey((int) $id)
                ->where('is_directory', true)
                ->where('is_host_file', false)
                ->first(),
            default => null,
        };
    }
}
