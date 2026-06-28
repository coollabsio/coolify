<?php

namespace App\Livewire\Project\Database\Mongodb;

use App\Actions\Database\StartDatabaseProxy;
use App\Actions\Database\StopDatabaseProxy;
use App\Models\Server;
use App\Models\StandaloneMongodb;
use App\Support\ValidationPatterns;
use Exception;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class General extends Component
{
    use AuthorizesRequests;

    public ?Server $server = null;

    public StandaloneMongodb $database;

    public string $name;

    public ?string $description = null;

    public ?string $mongoConf = null;

    public string $mongoInitdbRootUsername;

    public string $mongoInitdbRootPassword;

    public string $mongoInitdbDatabase;

    public string $image;

    public ?string $portsMappings = null;

    public ?bool $isPublic = null;

    public mixed $publicPort = null;

    public mixed $publicPortTimeout = 3600;

    public bool $isLogDrainEnabled = false;

    public ?string $customDockerRunOptions = null;

    protected function rules(): array
    {
        return [
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
            'mongoConf' => 'nullable',
            'mongoInitdbRootUsername' => ValidationPatterns::databaseIdentifierRules(
                enforcePattern: $this->mongoInitdbRootUsername !== $this->database->mongo_initdb_root_username,
            ),
            'mongoInitdbRootPassword' => ValidationPatterns::databasePasswordRules(
                enforcePattern: $this->mongoInitdbRootPassword !== $this->database->mongo_initdb_root_password,
            ),
            'mongoInitdbDatabase' => ValidationPatterns::databaseIdentifierRules(
                enforcePattern: $this->mongoInitdbDatabase !== $this->database->mongo_initdb_database,
            ),
            'image' => 'required',
            'portsMappings' => ValidationPatterns::portMappingRules(),
            'isPublic' => 'nullable|boolean',
            'publicPort' => 'nullable|integer|min:1|max:65535',
            'publicPortTimeout' => 'nullable|integer|min:1',
            'isLogDrainEnabled' => 'nullable|boolean',
            'customDockerRunOptions' => 'nullable',
        ];
    }

    protected function messages(): array
    {
        return array_merge(
            ValidationPatterns::combinedMessages(),
            ValidationPatterns::portMappingMessages(),
            [
                'name.required' => 'The Name field is required.',
                ...ValidationPatterns::databaseIdentifierMessages('mongoInitdbRootUsername', 'Root Username'),
                ...ValidationPatterns::databasePasswordMessages('mongoInitdbRootPassword', 'Root Password'),
                ...ValidationPatterns::databaseIdentifierMessages('mongoInitdbDatabase', 'MongoDB Database'),
                'image.required' => 'The Docker Image field is required.',
                'publicPort.integer' => 'The Public Port must be an integer.',
                'publicPort.min' => 'The Public Port must be at least 1.',
                'publicPort.max' => 'The Public Port must not exceed 65535.',
                'publicPortTimeout.integer' => 'The Public Port Timeout must be an integer.',
                'publicPortTimeout.min' => 'The Public Port Timeout must be at least 1.',
            ]
        );
    }

    protected $validationAttributes = [
        'name' => 'Name',
        'description' => 'Description',
        'mongoConf' => 'Mongo Configuration',
        'mongoInitdbRootUsername' => 'Root Username',
        'mongoInitdbRootPassword' => 'Root Password',
        'mongoInitdbDatabase' => 'Database',
        'image' => 'Image',
        'portsMappings' => 'Port Mapping',
        'isPublic' => 'Is Public',
        'publicPort' => 'Public Port',
        'publicPortTimeout' => 'Public Port Timeout',
        'customDockerRunOptions' => 'Custom Docker Run Options',
    ];

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
            $this->database->mongo_conf = $this->mongoConf;
            $this->database->mongo_initdb_root_username = $this->mongoInitdbRootUsername;
            $this->database->mongo_initdb_root_password = $this->mongoInitdbRootPassword;
            $this->database->mongo_initdb_database = $this->mongoInitdbDatabase;
            $this->database->image = $this->image;
            $this->database->ports_mappings = $this->portsMappings;
            $this->database->is_public = $this->isPublic;
            $this->database->public_port = $this->publicPort ?: null;
            $this->database->public_port_timeout = $this->publicPortTimeout ?: null;
            $this->database->is_log_drain_enabled = $this->isLogDrainEnabled;
            $this->database->custom_docker_run_options = $this->customDockerRunOptions;
            $this->database->save();
        } else {
            $this->name = $this->database->name;
            $this->description = $this->database->description;
            $this->mongoConf = $this->database->mongo_conf;
            $this->mongoInitdbRootUsername = $this->database->mongo_initdb_root_username;
            $this->mongoInitdbRootPassword = $this->database->mongo_initdb_root_password;
            $this->mongoInitdbDatabase = $this->database->mongo_initdb_database;
            $this->image = $this->database->image;
            $this->portsMappings = $this->database->ports_mappings;
            $this->isPublic = $this->database->is_public;
            $this->publicPort = $this->database->public_port;
            $this->publicPortTimeout = $this->database->public_port_timeout;
            $this->isLogDrainEnabled = $this->database->is_log_drain_enabled;
            $this->customDockerRunOptions = $this->database->custom_docker_run_options;
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

    public function submit()
    {
        try {
            $this->authorize('update', $this->database);

            if ($this->portsMappings) {
                $this->portsMappings = str($this->portsMappings)->replace(' ', '')->trim()->toString();
            }
            if (str($this->publicPort)->isEmpty()) {
                $this->publicPort = null;
            }
            if (str($this->mongoConf)->isEmpty()) {
                $this->mongoConf = null;
            }
            $this->syncData(true);
            $this->dispatch('success', 'Database updated.');
            $this->dispatch('databaseUpdated');
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
            $this->dispatch('databaseUpdated');
        } catch (\Throwable $e) {
            $this->isPublic = ! $this->isPublic;
            $this->syncData(true);

            return handleError($e, $this);
        }
    }

    public function refresh(): void
    {
        $this->database->refresh();
        $this->syncData();
    }

    public function render()
    {
        return view('livewire.project.database.mongodb.general');
    }
}
