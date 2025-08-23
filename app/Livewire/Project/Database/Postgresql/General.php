<?php

namespace App\Livewire\Project\Database\Postgresql;

use App\Actions\Database\StartDatabaseProxy;
use App\Actions\Database\StopDatabaseProxy;
use App\Helpers\SslHelper;
use App\Models\Server;
use App\Models\SslCertificate;
use App\Models\StandalonePostgresql;
use App\Support\ValidationPatterns;
use Carbon\Carbon;
use Exception;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class General extends Component
{
    use AuthorizesRequests;

    public StandalonePostgresql $database;

    public Server $server;

    public string $new_filename;

    public string $new_content;

    public ?string $db_url = null;

    public ?string $db_url_public = null;

    public ?Carbon $certificateValidUntil = null;

    public function getListeners()
    {
        $userId = Auth::id();

        return [
            "echo-private:user.{$userId},DatabaseStatusChanged" => '$refresh',
            'refresh' => '$refresh',
            'save_init_script',
            'delete_init_script',
        ];
    }

    protected function rules(): array
    {
        return [
            'database.name' => ValidationPatterns::nameRules(),
            'database.description' => ValidationPatterns::descriptionRules(),
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
            'database.enable_ssl' => 'boolean',
            'database.ssl_mode' => 'nullable|string|in:allow,prefer,require,verify-ca,verify-full',
        ];
    }

    protected function messages(): array
    {
        return array_merge(
            ValidationPatterns::combinedMessages(),
            [
                'database.name.required' => 'The Name field is required.',
                'database.name.regex' => 'The Name may only contain letters, numbers, spaces, dashes (-), underscores (_), dots (.), slashes (/), colons (:), and parentheses ().',
                'database.description.regex' => 'The Description contains invalid characters. Only letters, numbers, spaces, and common punctuation (- _ . : / () \' " , ! ? @ # % & + = [] {} | ~ ` *) are allowed.',
                'database.postgres_user.required' => 'The Postgres User field is required.',
                'database.postgres_password.required' => 'The Postgres Password field is required.',
                'database.postgres_db.required' => 'The Postgres Database field is required.',
                'database.image.required' => 'The Docker Image field is required.',
                'database.public_port.integer' => 'The Public Port must be an integer.',
                'database.ssl_mode.in' => 'The SSL Mode must be one of: allow, prefer, require, verify-ca, verify-full.',
            ]
        );
    }

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
        'database.enable_ssl' => 'Enable SSL',
        'database.ssl_mode' => 'SSL Mode',
    ];

    public function mount()
    {
        $this->db_url = $this->database->internal_db_url;
        $this->db_url_public = $this->database->external_db_url;
        $this->server = data_get($this->database, 'destination.server');

        $existingCert = $this->database->sslCertificates()->first();

        if ($existingCert) {
            $this->certificateValidUntil = $existingCert->valid_until;
        }
    }

    public function instantSaveAdvanced()
    {
        try {
            $this->authorize('update', $this->database);

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

    public function updatedDatabaseSslMode()
    {
        $this->instantSaveSSL();
    }

    public function instantSaveSSL()
    {
        try {
            $this->authorize('update', $this->database);

            $this->database->save();
            $this->dispatch('success', 'SSL configuration updated.');
            $this->db_url = $this->database->internal_db_url;
            $this->db_url_public = $this->database->external_db_url;
        } catch (Exception $e) {
            return handleError($e, $this);
        }
    }

    public function regenerateSslCertificate()
    {
        try {
            $this->authorize('update', $this->database);

            $existingCert = $this->database->sslCertificates()->first();

            if (! $existingCert) {
                $this->dispatch('error', 'No existing SSL certificate found for this database.');

                return;
            }

            $caCert = SslCertificate::where('server_id', $existingCert->server_id)->where('is_ca_certificate', true)->first();

            SslHelper::generateSslCertificate(
                commonName: $existingCert->common_name,
                subjectAlternativeNames: $existingCert->subject_alternative_names ?? [],
                resourceType: $existingCert->resource_type,
                resourceId: $existingCert->resource_id,
                serverId: $existingCert->server_id,
                caCert: $caCert->ssl_certificate,
                caKey: $caCert->ssl_private_key,
                configurationDir: $existingCert->configuration_dir,
                mountPath: $existingCert->mount_path,
                isPemKeyFileRequired: true,
            );

            $this->dispatch('success', 'SSL certificates have been regenerated. Please restart the database for changes to take effect.');
        } catch (Exception $e) {
            return handleError($e, $this);
        }
    }

    public function instantSave()
    {
        try {
            $this->authorize('update', $this->database);

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
        $this->authorize('update', $this->database);

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
            } catch (Exception $e) {
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
        $this->authorize('update', $this->database);

        $collection = collect($this->database->init_scripts);
        $found = $collection->firstWhere('filename', $script['filename']);
        if ($found) {
            $container_name = $this->database->uuid;
            $configuration_dir = database_configuration_dir().'/'.$container_name;
            $file_path = "$configuration_dir/docker-entrypoint-initdb.d/{$script['filename']}";

            $command = "rm -f $file_path";
            try {
                instant_remote_process([$command], $this->server);
            } catch (Exception $e) {
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
            $this->authorize('update', $this->database);

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
