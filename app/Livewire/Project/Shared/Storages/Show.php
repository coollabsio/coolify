<?php

namespace App\Livewire\Project\Shared\Storages;

use App\Models\LocalPersistentVolume;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesRequests;

    public LocalPersistentVolume $storage;

    public $resource;

    public bool $isReadOnly = false;

    public bool $isFirst = true;

    public bool $isService = false;

    public ?string $startedAt = null;

    // Explicit properties
    public string $name;

    public string $mountPath;

    public ?string $hostPath = null;

    public bool $isPreviewSuffixEnabled = true;

    public bool $isNameAsIs = false;

    public bool $canUseNameAsIs = false;

    protected $validationAttributes = [
        'name' => 'name',
        'mountPath' => 'mount',
        'hostPath' => 'host',
    ];

    protected function rules(): array
    {
        return [
            'name' => ValidationPatterns::volumeNameRules(),
            'mountPath' => ['required', 'string', 'regex:'.ValidationPatterns::DIRECTORY_PATH_PATTERN],
            'hostPath' => ['nullable', 'string', 'regex:'.ValidationPatterns::DIRECTORY_PATH_PATTERN],
            'isPreviewSuffixEnabled' => 'required|boolean',
            'isNameAsIs' => 'required|boolean',
        ];
    }

    protected function messages(): array
    {
        return array_merge(
            ValidationPatterns::volumeNameMessages(),
            [
                'mountPath.regex' => 'Mount path must start with / and only contain safe path characters.',
                'hostPath.regex' => 'Host path must start with / and only contain safe path characters.',
            ]
        );
    }

    /**
     * Sync data between component properties and model
     *
     * @param  bool  $toModel  If true, sync FROM properties TO model. If false, sync FROM model TO properties.
     */
    private function syncData(bool $toModel = false): void
    {
        if ($toModel) {
            // Sync TO model (before save)
            $this->storage->name = $this->name;
            $this->storage->mount_path = $this->mountPath;
            $this->storage->host_path = $this->hostPath;
            $this->storage->is_preview_suffix_enabled = $this->isPreviewSuffixEnabled;
            $this->storage->is_name_as_is = $this->isNameAsIs;
        } else {
            // Sync FROM model (on load/refresh)
            $this->name = $this->storage->name;
            $this->mountPath = $this->storage->mount_path;
            $this->hostPath = $this->storage->host_path;
            $this->isPreviewSuffixEnabled = $this->storage->is_preview_suffix_enabled ?? true;
            $this->isNameAsIs = $this->storage->is_name_as_is ?? false;
        }
    }

    public function mount()
    {
        $this->syncData(false);
        $this->isReadOnly = $this->storage->shouldBeReadOnlyInUI();
        $this->canUseNameAsIs = $this->supportsNameAsIs();
    }

    public function instantSave(): void
    {
        $this->authorize('update', $this->resource);
        $this->validate();
        $this->enforceNameAsIsSupport();

        $this->syncData(true);
        $this->storage->save();
        $this->dispatch('success', 'Storage updated successfully');
    }

    public function submit()
    {
        $this->authorize('update', $this->resource);

        $this->validate();
        $this->enforceNameAsIsSupport();
        $this->syncData(true);
        $this->storage->save();
        $this->dispatch('success', 'Storage updated successfully');
    }

    public function delete($password, $selectedActions = [])
    {
        $this->authorize('update', $this->resource);

        if (! verifyPasswordConfirmation($password, $this)) {
            return 'The provided password is incorrect.';
        }

        $this->storage->delete();
        $this->dispatch('refreshStorages');

        return true;
    }

    private function enforceNameAsIsSupport(): void
    {
        $this->canUseNameAsIs = $this->supportsNameAsIs();
        if (! $this->canUseNameAsIs) {
            $this->isNameAsIs = false;
        }
    }

    private function supportsNameAsIs(): bool
    {
        $this->storage->loadMissing('resource');

        return $this->storage->isServiceResource() || $this->storage->isDockerComposeResource();
    }
}
