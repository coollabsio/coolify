<?php

namespace App\Livewire\Project\Service;

use App\Models\Service;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Component;

class EditCompose extends Component
{
    use AuthorizesRequests;

    public Service $service;

    #[Locked]
    public $serviceId;

    public ?string $dockerComposeRaw = null;

    public ?string $dockerCompose = null;

    public bool $isContainerLabelEscapeEnabled = false;

    protected $listeners = [
        'refreshEnvs',
        'envsUpdated',
        'refresh' => 'envsUpdated',
    ];

    protected $rules = [
        'dockerComposeRaw' => 'required',
        'dockerCompose' => 'required',
        'isContainerLabelEscapeEnabled' => 'required',
    ];

    public function envsUpdated()
    {
        $this->dispatch('saveCompose', $this->dockerComposeRaw);
        $this->refreshEnvs();
    }

    public function refreshEnvs()
    {
        $this->service->refresh();
        $this->syncData(false);
    }

    public function mount()
    {
        $this->service = Service::ownedByCurrentTeam()->find($this->serviceId);
        $this->syncData(false);
    }

    private function syncData(bool $toModel = false): void
    {
        if ($toModel) {
            $this->service->docker_compose_raw = $this->dockerComposeRaw;
            $this->service->is_container_label_escape_enabled = $this->isContainerLabelEscapeEnabled;
        } else {
            $canViewCompose = auth()->user()?->can('update', $this->service) ?? false;
            $this->dockerComposeRaw = $canViewCompose ? $this->service->docker_compose_raw : null;
            $this->dockerCompose = $canViewCompose ? redactLockedValuesInGeneratedCompose($this->service->docker_compose, $this->service) : null;
            $this->isContainerLabelEscapeEnabled = $this->service->is_container_label_escape_enabled ?? false;
        }
    }

    public function validateCompose()
    {
        try {
            $this->authorize('update', $this->service);
            $isValid = validateComposeFile($this->dockerComposeRaw, $this->service->server_id);
            if ($isValid !== 'OK') {
                $this->dispatch('error', "Invalid docker-compose file.\n$isValid");
            } else {
                $this->dispatch('success', 'Docker compose is valid.');
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function saveEditedCompose()
    {
        try {
            $this->authorize('update', $this->service);
            $this->dispatch('saveCompose', $this->dockerComposeRaw);
            $this->dispatch('storageCountsChanged')->to(Storage::class);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function instantSave()
    {
        try {
            $this->authorize('update', $this->service);
            $this->validate([
                'isContainerLabelEscapeEnabled' => 'required',
            ]);
            $this->service->refresh()->update([
                'is_container_label_escape_enabled' => $this->isContainerLabelEscapeEnabled,
            ]);
            $this->dispatch('success', 'Service updated successfully');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.service.edit-compose');
    }
}
