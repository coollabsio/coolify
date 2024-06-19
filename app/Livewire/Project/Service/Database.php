<?php

namespace App\Livewire\Project\Service;

use App\Actions\Database\StartDatabaseProxy;
use App\Actions\Database\StopDatabaseProxy;
use App\Models\ServiceDatabase;
use Livewire\Component;

class Database extends Component
{
    public ServiceDatabase $database;

    public ?string $db_url_public = null;

    public $fileStorages;

    protected $listeners = ['refreshFileStorages'];

    protected $rules = [
        'database.human_name' => 'nullable',
        'database.description' => 'nullable',
        'database.image' => 'required',
        'database.exclude_from_status' => 'required|boolean',
        'database.public_port' => 'nullable|integer',
        'database.is_public' => 'required|boolean',
        'database.is_log_drain_enabled' => 'required|boolean',
    ];

    public function render()
    {
        return view('livewire.project.service.database');
    }

    public function mount()
    {
        if ($this->database->is_public) {
            $this->db_url_public = $this->database->getServiceDatabaseUrl();
        }
        $this->refreshFileStorages();
    }

    public function instantSaveExclude()
    {
        $this->submit();
    }

    public function instantSaveLogDrain()
    {
        if (! $this->database->service->destination->server->isLogDrainEnabled()) {
            $this->database->is_log_drain_enabled = false;
            $this->dispatch('error', 'Log drain is not enabled on the server. Please enable it first.');

            return;
        }
        $this->submit();
        $this->dispatch('success', 'You need to restart the service for the changes to take effect.');
    }

    public function instantSave()
    {
        if ($this->database->is_public && ! $this->database->public_port) {
            $this->dispatch('error', 'Public port is required.');
            $this->database->is_public = false;

            return;
        }
        if ($this->database->is_public) {
            if (! str($this->database->status)->startsWith('running')) {
                $this->dispatch('error', 'Database must be started to be publicly accessible.');
                $this->database->is_public = false;

                return;
            }
            StartDatabaseProxy::run($this->database);
            $this->db_url_public = $this->database->getServiceDatabaseUrl();
            $this->dispatch('success', 'Database is now publicly accessible.');
        } else {
            StopDatabaseProxy::run($this->database);
            $this->db_url_public = null;
            $this->dispatch('success', 'Database is no longer publicly accessible.');
        }
        $this->submit();
    }

    public function refreshFileStorages()
    {
        $this->fileStorages = $this->database->fileStorages()->get();
    }

    public function submit()
    {
        try {
            $this->validate();
            $this->database->save();
            updateCompose($this->database);
            $this->dispatch('success', 'Database saved.');
        } catch (\Throwable $e) {
            ray($e);
        } finally {
            $this->dispatch('generateDockerCompose');
        }
    }
}
