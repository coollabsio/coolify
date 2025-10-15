<?php

namespace App\Livewire\Project\Database\Postgresql;

use App\Actions\Database\StartDatabaseProxy;
use App\Actions\Database\StopDatabaseProxy;
use App\Helpers\SslHelper;
use App\Models\Server;
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

    public ?int $publicPort = null;

    public bool $isLogDrainEnabled = false;

    public ?string $customDockerRunOptions = null;

    public bool $enableSsl = false;

    public ?string $sslMode = null;

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
            'save_init_script',
            'delete_init_script',
        ];
    }

    protected function rules(): array
    {
        return [
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
            'postgresUser' => 'required',
            'postgresPassword' => 'required',
            'postgresDb' => 'required',
            'postgresInitdbArgs' => 'nullable',
            'postgresHostAuthMethod' => 'nullable',
            'postgresConf' => 'nullable',
            'initScripts' => 'nullable',
            'image' => 'required',
            'portsMappings' => 'nullable',
            'isPublic' => 'nullable|boolean',
            'publicPort' => 'nullable|integer',
            'isLogDrainEnabled' => 'nullable|boolean',
            'customDockerRunOptions' => 'nullable',
            'enableSsl' => 'boolean',
            'sslMode' => 'nullable|string|in:allow,prefer,require,verify-ca,verify-full',
        ];
    }

    protected function messages(): array
    {
        return array_merge(
            ValidationPatterns::combinedMessages(),
            [
                'name.required' => 'The Name field is required.',
                'name.regex' => 'The Name may only contain letters, numbers, spaces, dashes (-), underscores (_), dots (.), slashes (/), colons (:), and parentheses ().',
                'description.regex' => 'The Description contains invalid characters. Only letters, numbers, spaces, and common punctuation (- _ . : / () \' " , ! ? @ # % & + = [] {} | ~ ` *) are allowed.',
                'postgresUser.required' => 'The Postgres User field is required.',
                'postgresPassword.required' => 'The Postgres Password field is required.',
                'postgresDb.required' => 'The Postgres Database field is required.',
                'image.required' => 'The Docker Image field is required.',
                'publicPort.integer' => 'The Public Port must be an integer.',
                'sslMode.in' => 'The SSL Mode must be one of: allow, prefer, require, verify-ca, verify-full.',
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
        'customDockerRunOptions' => 'Custom Docker Run Options',
        'enableSsl' => 'Enable SSL',
        'sslMode' => 'SSL Mode',
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

            $existingCert = $this->database->sslCertificates()->first();

            if ($existingCert) {
                $this->certificateValidUntil = $existingCert->valid_until;
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
            $this->database->public_port = $this->publicPort;
            $this->database->is_log_drain_enabled = $this->isLogDrainEnabled;
            $this->database->custom_docker_run_options = $this->customDockerRunOptions;
            $this->database->enable_ssl = $this->enableSsl;
            $this->database->ssl_mode = $this->sslMode;
            $this->database->save();

            $this->db_url = $this->database->internal_db_url;
            $this->db_url_public = $this->database->external_db_url;
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
            $this->isLogDrainEnabled = $this->database->is_log_drain_enabled;
            $this->customDockerRunOptions = $this->database->custom_docker_run_options;
            $this->enableSsl = $this->database->enable_ssl;
            $this->sslMode = $this->database->ssl_mode;
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

    public function updatedSslMode()
    {
        $this->instantSaveSSL();
    }

    public function instantSaveSSL()
    {
        try {
            $this->authorize('update', $this->database);

            $this->syncData(true);
            $this->dispatch('success', 'SSL configuration updated.');
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

            $caCert = $this->server->sslCertificates()->where('is_ca_certificate', true)->first();

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

            if ($this->isPublic && ! $this->publicPort) {
                $this->dispatch('error', 'Public port is required.');
                $this->isPublic = false;

                return;
            }
            if ($this->isPublic) {
                if (! str($this->database->status)->startsWith('running')) {
                    $this->dispatch('error', 'Database must be started to be publicly accessible.');
                    $this->isPublic = false;

                    return;
                }
                StartDatabaseProxy::run($this->database);
                $this->dispatch('success', 'Database is now publicly accessible.');
            } else {
                StopDatabaseProxy::run($this->database);
                $this->dispatch('success', 'Database is no longer publicly accessible.');
            }
            $this->syncData(true);
        } catch (\Throwable $e) {
            $this->isPublic = ! $this->isPublic;

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
}
