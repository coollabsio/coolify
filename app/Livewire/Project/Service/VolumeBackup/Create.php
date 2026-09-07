<?php

namespace App\Livewire\Project\Service\VolumeBackup;

use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\Service;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Create extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public Service $service;

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
        $this->authorize('view', $this->service);
        $this->targetLocked = $this->selectedTargetKey !== null;
        $this->targetKey = $this->selectedTargetKey;
        $this->targets = $this->availableTargets();
        $this->targetKey ??= data_get($this->targets->first(), 'key');
        $this->loadSelectedBackup();
    }

    public function updatedTargetKey(): void
    {
        $this->loadSelectedBackup();
    }

    public function submit(): void
    {
        $this->authorize('update', $this->service);
        $this->validate();
        $target = $this->selectedTarget();

        if (! $target) {
            $this->addError('targetKey', 'Select a volume or directory owned by this service.');

            return;
        }
        if (! validate_cron_expression($this->frequency)) {
            $this->addError('frequency', 'The frequency must be a valid cron or human expression.');

            return;
        }

        try {
            $backup = $target->scheduledBackups()->updateOrCreate([], [
                'team_id' => currentTeam()->id,
                'frequency' => $this->frequency,
                'enabled' => true,
            ]);
            $this->dispatch('success', $backup->wasRecentlyCreated ? 'Scheduled storage backup created.' : 'Scheduled storage backup updated.');
            redirectRoute($this, 'project.service.volume-backups.show', [
                'project_uuid' => $this->service->project()->uuid,
                'environment_uuid' => $this->service->environment->uuid,
                'service_uuid' => $this->service->uuid,
                'backup_uuid' => $backup->uuid,
            ]);
        } catch (\Throwable $exception) {
            handleError($exception, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.application.backup.create');
    }

    private function availableTargets(): Collection
    {
        $resources = $this->service->applications()->get()->concat($this->service->databases()->get());
        $targets = collect();

        foreach ($resources as $resource) {
            $label = str($resource->name)->headline();
            $targets->push(...$resource->persistentStorages()->orderBy('name')->get()->map(fn (LocalPersistentVolume $volume): array => [
                'key' => 'volume:'.$volume->id,
                'type' => $label,
                'name' => str($volume->name)->after($this->service->uuid.'_')->value(),
            ]));
            $targets->push(...$resource->fileStorages()
                ->where('is_directory', true)
                ->where('is_host_file', false)
                ->orderBy('fs_path')
                ->get()
                ->map(fn (LocalFileVolume $directory): array => [
                    'key' => 'directory:'.$directory->id,
                    'type' => $label,
                    'name' => $directory->fs_path.' (directory)',
                ]));
        }

        return $this->targetLocked
            ? $targets->where('key', $this->selectedTargetKey)->values()
            : $targets->values();
    }

    private function loadSelectedBackup(): void
    {
        $backup = $this->selectedTarget()?->scheduledBackups()->first();
        if ($backup) {
            $this->frequency = $backup->frequency;
        }
    }

    private function selectedTarget(): LocalPersistentVolume|LocalFileVolume|null
    {
        [$type, $id] = array_pad(explode(':', (string) $this->targetKey, 2), 2, null);
        if (! ctype_digit((string) $id) || ! in_array($type, ['volume', 'directory'], true)) {
            return null;
        }

        $target = ($type === 'volume' ? LocalPersistentVolume::query() : LocalFileVolume::query())->find((int) $id);
        if (! $target || ($type === 'directory' && (! $target->is_directory || $target->is_host_file))) {
            return null;
        }

        return $target->resource?->service_id === $this->service->id ? $target : null;
    }
}
