<?php

namespace App\Livewire\Project\Database\Postgresql;

use App\Actions\Database\StartDatabaseProxy;
use App\Actions\Database\StopDatabaseProxy;
use App\Models\Server;
use App\Models\StandalonePostgresql;
use App\Support\ValidationPatterns;
use Exception;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class General extends Component
{
    use AuthorizesRequests;

    public StandalonePostgresql $database;

    public ?Server $server = null;

    public string $name;

    public ?string $description = null;

    public string $postgresUser;

    public string $postgresPassword;

    public string $postgresDb;

    public ?string $postgresInitdbArgs = null;

    public ?string $postgresHostAuthMethod = null;

    public ?string $postgresConf = null;

    public ?array $initScripts = null;

    public string $image;

    public ?string $portsMappings = null;

    public ?bool $isPublic = null;

    public mixed $publicPort = null;

    public mixed $publicPortTimeout = 3600;

    public bool $isLogDrainEnabled = false;

    public ?string $customDockerRunOptions = null;

    public string $new_filename;

    public string $new_content;

    protected $listeners = [
        'save_init_script',
        'delete_init_script',
    ];

    protected function rules(): array
    {
        return [
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
            'postgresUser' => ValidationPatterns::databaseIdentifierRules(
                enforcePattern: $this->postgresUser !== $this->database->postgres_user,
            ),
            'postgresPassword' => ValidationPatterns::databasePasswordRules(
                enforcePattern: $this->postgresPassword !== $this->database->postgres_password,
            ),
            'postgresDb' => ValidationPatterns::databaseIdentifierRules(
                enforcePattern: $this->postgresDb !== $this->database->postgres_db,
            ),
            'postgresInitdbArgs' => 'nullable',
            'postgresHostAuthMethod' => 'nullable',
            'postgresConf' => 'nullable',
            'initScripts' => 'nullable',
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
                ...ValidationPatterns::databaseIdentifierMessages('postgresUser', 'Postgres User'),
                ...ValidationPatterns::databasePasswordMessages('postgresPassword', 'Postgres Password'),
                ...ValidationPatterns::databaseIdentifierMessages('postgresDb', 'Postgres Database'),
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
        'postgresUser' => 'Postgres User',
        'postgresPassword' => 'Postgres Password',
        'postgresDb' => 'Postgres DB',
        'postgresInitdbArgs' => 'Postgres Initdb Args',
        'postgresHostAuthMethod' => 'Postgres Host Auth Method',
        'postgresConf' => 'Postgres Configuration',
        'initScripts' => 'Init Scripts',
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
            $this->database->postgres_user = $this->postgresUser;
            $this->database->postgres_password = $this->postgresPassword;
            $this->database->postgres_db = $this->postgresDb;
            $this->database->postgres_initdb_args = $this->postgresInitdbArgs;
            $this->database->postgres_host_auth_method = $this->postgresHostAuthMethod;
            $this->database->postgres_conf = $this->postgresConf;
            $this->database->init_scripts = $this->initScripts;
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
            $this->postgresUser = $this->database->postgres_user;
            $this->postgresPassword = $this->database->postgres_password;
            $this->postgresDb = $this->database->postgres_db;
            $this->postgresInitdbArgs = $this->database->postgres_initdb_args;
            $this->postgresHostAuthMethod = $this->database->postgres_host_auth_method;
            $this->postgresConf = $this->database->postgres_conf;
            $this->initScripts = $this->database->init_scripts;
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

    public function save_init_script($script)
    {
        $this->authorize('update', $this->database);

        $initScripts = collect($this->initScripts ?? []);

        $existingScript = $initScripts->firstWhere('filename', $script['filename']);
        $oldScript = $initScripts->firstWhere('index', $script['index']);

        if ($existingScript && $existingScript['index'] !== $script['index']) {
            $this->dispatch('error', 'A script with this filename already exists.');

            return;
        }

        $container_name = $this->database->uuid;
        $configuration_dir = database_configuration_dir().'/'.$container_name;

        if ($oldScript && $oldScript['filename'] !== $script['filename']) {
            try {
                // New filename is user-supplied — must be safe before accepting the rename.
                validateFilenameSafe($script['filename'], 'init script filename');

                // Old filename may be a legacy value written before this validation existed.
                // basename() scopes the rm to the initdb.d directory; escapeshellarg() contains
                // any remaining shell-metachars. No validator — don't block cleanup of legacy rows.
                $old_filename = basename($oldScript['filename']);
                $old_file_path = "$configuration_dir/docker-entrypoint-initdb.d/{$old_filename}";
                $escapedOldPath = escapeshellarg($old_file_path);
                $delete_command = "rm -f {$escapedOldPath}";
                instant_remote_process([$delete_command], $this->server);
            } catch (Exception $e) {
                $this->dispatch('error', $e->getMessage());

                return;
            }
        }

        $index = $initScripts->search(function ($item) use ($script) {
            return $item['index'] === $script['index'];
        });

        if ($index !== false) {
            $initScripts[$index] = $script;
        } else {
            $initScripts->push($script);
        }

        $this->initScripts = $initScripts->values()
            ->map(function ($item, $index) {
                $item['index'] = $index;

                return $item;
            })
            ->all();

        $this->syncData(true);
        $this->dispatch('success', 'Init script saved and updated.');
    }

    public function delete_init_script($script)
    {
        $this->authorize('update', $this->database);

        $collection = collect($this->initScripts);
        $found = $collection->firstWhere('filename', $script['filename']);
        if ($found) {
            $container_name = $this->database->uuid;
            $configuration_dir = database_configuration_dir().'/'.$container_name;

            try {
                // Allow deletion of legacy rows with unsafe filenames so operators can clean up.
                // basename() scopes the rm to the initdb.d directory; escapeshellarg() keeps the
                // shell invocation safe regardless of the stored value.
                $safe_filename = basename($script['filename']);
                $file_path = "$configuration_dir/docker-entrypoint-initdb.d/{$safe_filename}";
                $escapedPath = escapeshellarg($file_path);

                $command = "rm -f {$escapedPath}";
                instant_remote_process([$command], $this->server);
            } catch (Exception $e) {
                $this->dispatch('error', $e->getMessage());

                return;
            }

            $updatedScripts = $collection->filter(fn ($s) => $s['filename'] !== $script['filename'])
                ->values()
                ->map(function ($item, $index) {
                    $item['index'] = $index;

                    return $item;
                })
                ->all();

            $this->initScripts = $updatedScripts;
            $this->syncData(true);
            $this->dispatch('refresh')->self();
            $this->dispatch('success', 'Init script deleted from the database and the server.');
        }
    }

    public function save_new_init_script()
    {
        $this->authorize('update', $this->database);

        $this->validate([
            'new_filename' => 'required|string',
            'new_content' => 'required|string',
        ]);

        try {
            // Validate filename to prevent path traversal and command injection
            validateFilenameSafe($this->new_filename, 'init script filename');
        } catch (Exception $e) {
            $this->dispatch('error', $e->getMessage());

            return;
        }

        $found = collect($this->initScripts)->firstWhere('filename', $this->new_filename);
        if ($found) {
            $this->dispatch('error', 'Filename already exists.');

            return;
        }
        if (! isset($this->initScripts)) {
            $this->initScripts = [];
        }
        $this->initScripts = array_merge($this->initScripts, [
            [
                'index' => count($this->initScripts),
                'filename' => $this->new_filename,
                'content' => $this->new_content,
            ],
        ]);
        $this->syncData(true);
        $this->dispatch('success', 'Init script added.');
        $this->new_content = '';
        $this->new_filename = '';
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

    public function refresh(): void
    {
        $this->database->refresh();
        $this->syncData();
    }
}
