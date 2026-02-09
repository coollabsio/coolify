<?php

namespace App\Livewire\Project\Database\Surrealdb;

use App\Actions\Database\StartDatabaseProxy;
use App\Actions\Database\StopDatabaseProxy;
use App\Models\Server;
use App\Models\StandaloneSurrealdb;
use App\Support\ValidationPatterns;
use Exception;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class General extends Component
{
    use AuthorizesRequests;

    public StandaloneSurrealdb $database;

    public ?Server $server = null;

    public string $name;

    public ?string $description = null;

    public string $surrealdbUser;

    public string $surrealdbPassword;

    public bool $useTikv = false;

    public ?string $tikvEndpoint = null;

    public string $image;

    public ?string $portsMappings = null;

    public ?bool $isPublic = null;

    public ?int $publicPort = null;

    public string $publicProxyTimeout = '600s';

    public bool $isLogDrainEnabled = false;

    public ?string $customDockerRunOptions = null;

    public ?string $db_url = null;

    public ?string $db_url_public = null;

    public function getListeners()
    {
        $userId = Auth::id();

        return [
            "echo-private:user.{$userId},DatabaseStatusChanged" => '$refresh',
        ];
    }

    protected function rules(): array
    {
        return [
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
            'surrealdbUser' => 'required',
            'surrealdbPassword' => 'required',
            'useTikv' => 'boolean',
            'tikvEndpoint' => 'nullable',
            'image' => 'required',
            'portsMappings' => 'nullable',
            'isPublic' => 'nullable|boolean',
            'publicPort' => 'nullable|integer',
            'publicProxyTimeout' => 'required|string',
            'isLogDrainEnabled' => 'nullable|boolean',
            'customDockerRunOptions' => 'nullable',
        ];
    }

    protected function messages(): array
    {
        return array_merge(
            ValidationPatterns::combinedMessages(),
            [
                'name.required' => 'The Name field is required.',
                'surrealdbUser.required' => 'The SurrealDB User field is required.',
                'surrealdbPassword.required' => 'The SurrealDB Password field is required.',
                'image.required' => 'The Docker Image field is required.',
                'publicPort.integer' => 'The Public Port must be an integer.',
            ]
        );
    }

    public function mount()
    {
        try {
            $this->authorize('view', $this->database);
            $this->syncData();
            $this->server = data_get($this->database, 'destination.server');
            if (! $this->server) {
                $this->dispatch('error', 'Database destination server is not configured.');

                return;
            }
        } catch (Exception $e) {
            return handleError($e, $this);
        }
    }

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            $this->validate();
            $this->database->name = $this->name;
            $this->database->description = $this->description;
            $this->database->surrealdb_user = $this->surrealdbUser;
            $this->database->surrealdb_password = $this->surrealdbPassword;
            $this->database->use_tikv = $this->useTikv;
            $this->database->tikv_endpoint = $this->tikvEndpoint;
            $this->database->image = $this->image;
            $this->database->ports_mappings = $this->portsMappings;
            $this->database->is_public = $this->isPublic;
            $this->database->public_port = $this->publicPort;
            $this->database->public_proxy_timeout = $this->publicProxyTimeout;
            $this->database->is_log_drain_enabled = $this->isLogDrainEnabled;
            $this->database->custom_docker_run_options = $this->customDockerRunOptions;
            $this->database->save();

            $this->db_url = $this->database->internal_db_url;
            $this->db_url_public = $this->database->external_db_url;
        } else {
            $this->name = $this->database->name;
            $this->description = $this->database->description;
            $this->surrealdbUser = $this->database->surrealdb_user;
            $this->surrealdbPassword = $this->database->surrealdb_password;
            $this->useTikv = $this->database->use_tikv;
            $this->tikvEndpoint = $this->database->tikv_endpoint;
            $this->image = $this->database->image;
            $this->portsMappings = $this->database->ports_mappings;
            $this->isPublic = $this->database->is_public;
            $this->publicPort = $this->database->public_port;
            $this->publicProxyTimeout = $this->database->public_proxy_timeout ?? '600s';
            $this->isLogDrainEnabled = $this->database->is_log_drain_enabled;
            $this->customDockerRunOptions = $this->database->custom_docker_run_options;
            $this->db_url = $this->database->internal_db_url;
            $this->db_url_public = $this->database->external_db_url;
        }
    }

    public function instantSaveAdvanced()
    {
        try {
            $this->authorize('update', $this->database);

            if (! $this->server->isLogDrainEnabled()) {
                $this->isLogDrainEnabled = false;
                $this->dispatch('error', 'Log drain is not enabled on the server. Please enable it first.');

                return;
            }
            $this->syncData(true);
            $this->dispatch('success', 'Database updated.');
            $this->dispatch('success', 'You need to restart the service for the changes to take effect.');
        } catch (Exception $e) {
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
            if ($this->isPublic && ! str($this->database->status)->startsWith('running')) {
                $this->dispatch('error', 'Database must be started to be publicly accessible.');
                $this->isPublic = false;

                return;
            }
            $this->syncData(true);
            if ($this->isPublic) {
                StartDatabaseProxy::run($this->database);
                $this->dispatch('success', 'Database is now publicly accessible.');
            } else {
                StopDatabaseProxy::run($this->database);
                $this->dispatch('success', 'Database is no longer publicly accessible.');
            }
        } catch (\Throwable $e) {
            $this->isPublic = ! $this->isPublic;
            $this->syncData(true);

            return handleError($e, $this);
        }
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->database);

            if (str($this->publicPort)->isEmpty()) {
                $this->publicPort = null;
            }
            $this->syncData(true);
            $this->dispatch('success', 'Database updated.');
        } catch (Exception $e) {
            return handleError($e, $this);
        } finally {
            if (is_null($this->database->config_hash)) {
                $this->database->isConfigurationChanged(true);
            } else {
                $this->dispatch('configurationChanged');
            }
        }
    }

    public function render()
    {
        return view('livewire.project.database.surrealdb.general');
    }
}
