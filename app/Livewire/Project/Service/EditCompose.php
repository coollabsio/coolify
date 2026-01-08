<?php

namespace App\Livewire\Project\Service;

use App\Models\Service;
use Livewire\Component;

class EditCompose extends Component
{
    public Service $service;

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
        $this->service = Service::ownedByCurrentTeam()->find($this->serviceId);
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
            $this->service->docker_compose = $this->dockerCompose;
            $this->service->is_container_label_escape_enabled = $this->isContainerLabelEscapeEnabled;
        } else {
            $this->dockerComposeRaw = $this->service->docker_compose_raw;
            $this->dockerCompose = $this->service->docker_compose;
            $this->isContainerLabelEscapeEnabled = $this->service->is_container_label_escape_enabled ?? false;
        }
    }

    public function validateCompose()
    {
        $isValid = validateComposeFile($this->dockerComposeRaw, $this->service->server_id);
        if ($isValid !== 'OK') {
            $this->dispatch('error', "Invalid docker-compose file.\n$isValid");
        } else {
            $this->dispatch('success', 'Docker compose is valid.');
        }
    }

    public function saveEditedCompose()
    {
        $this->dispatch('info', 'Saving new docker compose...');
        $this->dispatch('saveCompose', $this->dockerComposeRaw);
        $this->dispatch('refreshStorages');
    }

    public function instantSave()
    {
        $this->validate([
            'isContainerLabelEscapeEnabled' => 'required',
        ]);
        $this->syncData(true);
        $this->service->save(['is_container_label_escape_enabled' => $this->isContainerLabelEscapeEnabled]);
        $this->dispatch('success', 'Service updated successfully');
    }

    public function render()
    {
        return view('livewire.project.service.edit-compose');
    }
}
