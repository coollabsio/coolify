<?php

namespace App\Livewire\Project\Service;

use App\Actions\Database\StartDatabaseProxy;
use App\Actions\Database\StopDatabaseProxy;
use App\Models\InstanceSettings;
use App\Models\ServiceDatabase;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Component;

class Database extends Component
{
    use AuthorizesRequests;

    public ServiceDatabase $database;

    public ?string $db_url_public = null;

    public $fileStorages;

    public $parameters;

    public ?string $humanName = null;

    public ?string $description = null;

    public ?string $image = null;

    public bool $excludeFromStatus = false;

    public ?int $publicPort = null;

    public bool $isPublic = false;

    public bool $isLogDrainEnabled = false;

    protected $listeners = ['refreshFileStorages'];

    protected $rules = [
        'humanName' => 'nullable',
        'description' => 'nullable',
        'image' => 'required',
        'excludeFromStatus' => 'required|boolean',
        'publicPort' => 'nullable|integer',
        'isPublic' => 'required|boolean',
        'isLogDrainEnabled' => 'required|boolean',
    ];

    public function render()
    {
        return view('livewire.project.service.database');
    }

    public function mount()
    {
        try {
            $this->parameters = get_route_parameters();
            $this->authorize('view', $this->database);
            if ($this->database->is_public) {
                $this->db_url_public = $this->database->getServiceDatabaseUrl();
            }
            $this->refreshFileStorages();
            $this->syncData(false);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    private function syncData(bool $toModel = false): void
    {
        if ($toModel) {
            $this->database->human_name = $this->humanName;
            $this->database->description = $this->description;
            $this->database->image = $this->image;
            $this->database->exclude_from_status = $this->excludeFromStatus;
            $this->database->public_port = $this->publicPort;
            $this->database->is_public = $this->isPublic;
            $this->database->is_log_drain_enabled = $this->isLogDrainEnabled;
        } else {
            $this->humanName = $this->database->human_name;
            $this->description = $this->database->description;
            $this->image = $this->database->image;
            $this->excludeFromStatus = $this->database->exclude_from_status ?? false;
            $this->publicPort = $this->database->public_port;
            $this->isPublic = $this->database->is_public ?? false;
            $this->isLogDrainEnabled = $this->database->is_log_drain_enabled ?? false;
        }
    }

    public function delete($password)
    {
        try {
            $this->authorize('delete', $this->database);

            if (! data_get(InstanceSettings::get(), 'disable_two_step_confirmation')) {
                if (! Hash::check($password, Auth::user()->password)) {
                    $this->addError('password', 'The provided password is incorrect.');

                    return;
                }
            }

            $this->database->delete();
            $this->dispatch('success', 'Database deleted.');

            return redirect()->route('project.service.configuration', $this->parameters);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function instantSaveExclude()
    {
        try {
            $this->authorize('update', $this->database);
            $this->submit();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function instantSaveLogDrain()
    {
        try {
            $this->authorize('update', $this->database);
            if (! $this->database->service->destination->server->isLogDrainEnabled()) {
                $this->isLogDrainEnabled = false;
                $this->dispatch('error', 'Log drain is not enabled on the server. Please enable it first.');

                return;
            }
            $this->submit();
            $this->dispatch('success', 'You need to restart the service for the changes to take effect.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function convertToApplication()
    {
        try {
            $this->authorize('update', $this->database);
            $service = $this->database->service;
            $serviceDatabase = $this->database;

            // Check if application with same name already exists
            if ($service->applications()->where('name', $serviceDatabase->name)->exists()) {
                throw new \Exception('An application with this name already exists.');
            }

            // Create new parameters removing database_uuid
            $redirectParams = collect($this->parameters)
                ->except('database_uuid')
                ->all();

            DB::transaction(function () use ($service, $serviceDatabase) {
                $service->applications()->create([
                    'name' => $serviceDatabase->name,
                    'human_name' => $serviceDatabase->human_name,
                    'description' => $serviceDatabase->description,
                    'exclude_from_status' => $serviceDatabase->exclude_from_status,
                    'is_log_drain_enabled' => $serviceDatabase->is_log_drain_enabled,
                    'image' => $serviceDatabase->image,
                    'service_id' => $service->id,
                    'is_migrated' => true,
                ]);
                $serviceDatabase->delete();
            });

            return redirect()->route('project.service.configuration', $redirectParams);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function instantSave()
    {
        try {
            $this->authorize('update', $this->database);
            if ($this->isPublic && ! $this->publicPort) {
                $this->dispatch('error', 'Public port is required.');
                $this->isPublic = false;

                return;
            }
            $this->syncData(true);
            if ($this->database->is_public) {
                if (! str($this->database->status)->startsWith('running')) {
                    $this->dispatch('error', 'Database must be started to be publicly accessible.');
                    $this->isPublic = false;
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
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function refreshFileStorages()
    {
        $this->fileStorages = $this->database->fileStorages()->get();
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->database);
            $this->validate();
            $this->syncData(true);
            $this->database->save();
            $this->database->refresh();
            $this->syncData(false);
            updateCompose($this->database);
            $this->dispatch('success', 'Database saved.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        } finally {
            $this->dispatch('generateDockerCompose');
        }
    }
}
