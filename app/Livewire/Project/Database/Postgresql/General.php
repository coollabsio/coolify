<?php

namespace App\Livewire\Project\Database\Postgresql;

use App\Actions\Database\StartDatabaseProxy;
use App\Actions\Database\StopDatabaseProxy;
use App\Models\Server;
use App\Models\StandalonePostgresql;
use Exception;
use Livewire\Component;

class General extends Component
{
    public StandalonePostgresql $database;

    public Server $server;

    public string $new_filename;

    public string $new_content;

    public ?string $db_url = null;

    public ?string $db_url_public = null;

    public function getListeners()
    {
        return [
            'refresh',
            'save_init_script',
            'delete_init_script',
        ];
    }

    protected $rules = [
        'database.name' => 'required',
        'database.description' => 'nullable',
        'database.postgres_user' => 'required',
        'database.postgres_password' => 'required',
        'database.postgres_db' => 'required',
        'database.postgres_initdb_args' => 'nullable',
        'database.postgres_host_auth_method' => 'nullable',
        'database.postgres_conf' => 'nullable',
        'database.init_scripts' => 'nullable',
        'database.image' => 'required',
        'database.ports_mappings' => 'nullable',
        'database.is_public' => 'nullable|boolean',
        'database.public_port' => 'nullable|integer',
        'database.is_log_drain_enabled' => 'nullable|boolean',
        'database.custom_docker_run_options' => 'nullable',
    ];

    protected $validationAttributes = [
        'database.name' => 'Name',
        'database.description' => 'Description',
        'database.postgres_user' => 'Postgres User',
        'database.postgres_password' => 'Postgres Password',
        'database.postgres_db' => 'Postgres DB',
        'database.postgres_initdb_args' => 'Postgres Initdb Args',
        'database.postgres_host_auth_method' => 'Postgres Host Auth Method',
        'database.postgres_conf' => 'Postgres Configuration',
        'database.init_scripts' => 'Init Scripts',
        'database.image' => 'Image',
        'database.ports_mappings' => 'Port Mapping',
        'database.is_public' => 'Is Public',
        'database.public_port' => 'Public Port',
        'database.custom_docker_run_options' => 'Custom Docker Run Options',
    ];

    public function mount()
    {
        $this->db_url = $this->database->internal_db_url;
        $this->db_url_public = $this->database->external_db_url;
        $this->server = data_get($this->database, 'destination.server');
    }

    public function instantSaveAdvanced()
    {
        try {
            if (! $this->server->isLogDrainEnabled()) {
                $this->database->is_log_drain_enabled = false;
                $this->dispatch('error', 'Log drain is not enabled on the server. Please enable it first.');

                return;
            }
            $this->database->save();
            $this->dispatch('success', 'Database updated.');
            $this->dispatch('success', 'You need to restart the service for the changes to take effect.');
        } catch (Exception $e) {
            return handleError($e, $this);
        }
    }

    public function instantSave()
    {
        try {
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
                $this->dispatch('success', 'Database is now publicly accessible.');
            } else {
                StopDatabaseProxy::run($this->database);
                $this->dispatch('success', 'Database is no longer publicly accessible.');
            }
            $this->db_url_public = $this->database->external_db_url;
            $this->database->save();
        } catch (\Throwable $e) {
            $this->database->is_public = ! $this->database->is_public;

            return handleError($e, $this);
        }
    }

    public function save_init_script($script)
    {
        $initScripts = collect($this->database->init_scripts ?? []);

        $existingScript = $initScripts->firstWhere('filename', $script['filename']);
        $oldScript = $initScripts->firstWhere('index', $script['index']);

        if ($existingScript && $existingScript['index'] !== $script['index']) {
            $this->dispatch('error', 'A script with this filename already exists.');

            return;
        }

        $container_name = $this->database->uuid;
        $configuration_dir = database_configuration_dir().'/'.$container_name;

        if ($oldScript && $oldScript['filename'] !== $script['filename']) {
            $old_file_path = "$configuration_dir/docker-entrypoint-initdb.d/{$oldScript['filename']}";
            $delete_command = "rm -f $old_file_path";
            try {
                instant_remote_process([$delete_command], $this->server);
            } catch (\Exception $e) {
                $this->dispatch('error', 'Failed to remove old init script from server: '.$e->getMessage());

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

        $this->database->init_scripts = $initScripts->values()
            ->map(function ($item, $index) {
                $item['index'] = $index;

                return $item;
            })
            ->all();

        $this->database->save();
        $this->dispatch('success', 'Init script saved and updated.');
    }

    public function delete_init_script($script)
    {
        $collection = collect($this->database->init_scripts);
        $found = $collection->firstWhere('filename', $script['filename']);
        if ($found) {
            $container_name = $this->database->uuid;
            $configuration_dir = database_configuration_dir().'/'.$container_name;
            $file_path = "$configuration_dir/docker-entrypoint-initdb.d/{$script['filename']}";

            $command = "rm -f $file_path";
            try {
                instant_remote_process([$command], $this->server);
            } catch (\Exception $e) {
                $this->dispatch('error', 'Failed to remove init script from server: '.$e->getMessage());

                return;
            }

            $updatedScripts = $collection->filter(fn ($s) => $s['filename'] !== $script['filename'])
                ->values()
                ->map(function ($item, $index) {
                    $item['index'] = $index;

                    return $item;
                })
                ->all();

            $this->database->init_scripts = $updatedScripts;
            $this->database->save();
            $this->refresh();
            $this->dispatch('success', 'Init script deleted from the database and the server.');
        }
    }

    public function refresh(): void
    {
        $this->database->refresh();
    }

    public function save_new_init_script()
    {
        $this->validate([
            'new_filename' => 'required|string',
            'new_content' => 'required|string',
        ]);
        $found = collect($this->database->init_scripts)->firstWhere('filename', $this->new_filename);
        if ($found) {
            $this->dispatch('error', 'Filename already exists.');

            return;
        }
        if (! isset($this->database->init_scripts)) {
            $this->database->init_scripts = [];
        }
        $this->database->init_scripts = array_merge($this->database->init_scripts, [
            [
                'index' => count($this->database->init_scripts),
                'filename' => $this->new_filename,
                'content' => $this->new_content,
            ],
        ]);
        $this->database->save();
        $this->dispatch('success', 'Init script added.');
        $this->new_content = '';
        $this->new_filename = '';
    }

    public function submit()
    {
        try {
            if (str($this->database->public_port)->isEmpty()) {
                $this->database->public_port = null;
            }
            $this->validate();
            $this->database->save();
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
}
