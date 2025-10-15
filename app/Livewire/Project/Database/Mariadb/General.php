<?php

namespace App\Livewire\Project\Database\Mariadb;

use App\Actions\Database\StartDatabaseProxy;
use App\Actions\Database\StopDatabaseProxy;
use App\Helpers\SslHelper;
use App\Models\Server;
use App\Models\StandaloneMariadb;
use App\Support\ValidationPatterns;
use Carbon\Carbon;
use Exception;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class General extends Component
{
    use AuthorizesRequests;

    public ?Server $server = null;

    public StandaloneMariadb $database;

    public string $name;

    public ?string $description = null;

    public string $mariadbRootPassword;

    public string $mariadbUser;

    public string $mariadbPassword;

    public string $mariadbDatabase;

    public ?string $mariadbConf = null;

    public string $image;

    public ?string $portsMappings = null;

    public ?bool $isPublic = null;

    public ?int $publicPort = null;

    public bool $isLogDrainEnabled = false;

    public ?string $customDockerRunOptions = null;

    public bool $enableSsl = false;

    public ?string $db_url = null;

    public ?string $db_url_public = null;

    public ?Carbon $certificateValidUntil = null;

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
            'mariadbRootPassword' => 'required',
            'mariadbUser' => 'required',
            'mariadbPassword' => 'required',
            'mariadbDatabase' => 'required',
            'mariadbConf' => 'nullable',
            'image' => 'required',
            'portsMappings' => 'nullable',
            'isPublic' => 'nullable|boolean',
            'publicPort' => 'nullable|integer',
            'isLogDrainEnabled' => 'nullable|boolean',
            'customDockerRunOptions' => 'nullable',
            'enableSsl' => 'boolean',
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
                'mariadbRootPassword.required' => 'The Root Password field is required.',
                'mariadbUser.required' => 'The MariaDB User field is required.',
                'mariadbPassword.required' => 'The MariaDB Password field is required.',
                'mariadbDatabase.required' => 'The MariaDB Database field is required.',
                'image.required' => 'The Docker Image field is required.',
                'publicPort.integer' => 'The Public Port must be an integer.',
            ]
        );
    }

    protected $validationAttributes = [
        'name' => 'Name',
        'description' => 'Description',
        'mariadbRootPassword' => 'Root Password',
        'mariadbUser' => 'User',
        'mariadbPassword' => 'Password',
        'mariadbDatabase' => 'Database',
        'mariadbConf' => 'MariaDB Configuration',
        'image' => 'Image',
        'portsMappings' => 'Port Mapping',
        'isPublic' => 'Is Public',
        'publicPort' => 'Public Port',
        'customDockerRunOptions' => 'Custom Docker Options',
        'enableSsl' => 'Enable SSL',
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
            $this->database->mariadb_root_password = $this->mariadbRootPassword;
            $this->database->mariadb_user = $this->mariadbUser;
            $this->database->mariadb_password = $this->mariadbPassword;
            $this->database->mariadb_database = $this->mariadbDatabase;
            $this->database->mariadb_conf = $this->mariadbConf;
            $this->database->image = $this->image;
            $this->database->ports_mappings = $this->portsMappings;
            $this->database->is_public = $this->isPublic;
            $this->database->public_port = $this->publicPort;
            $this->database->is_log_drain_enabled = $this->isLogDrainEnabled;
            $this->database->custom_docker_run_options = $this->customDockerRunOptions;
            $this->database->enable_ssl = $this->enableSsl;
            $this->database->save();

            $this->db_url = $this->database->internal_db_url;
            $this->db_url_public = $this->database->external_db_url;
        } else {
            $this->name = $this->database->name;
            $this->description = $this->database->description;
            $this->mariadbRootPassword = $this->database->mariadb_root_password;
            $this->mariadbUser = $this->database->mariadb_user;
            $this->mariadbPassword = $this->database->mariadb_password;
            $this->mariadbDatabase = $this->database->mariadb_database;
            $this->mariadbConf = $this->database->mariadb_conf;
            $this->image = $this->database->image;
            $this->portsMappings = $this->database->ports_mappings;
            $this->isPublic = $this->database->is_public;
            $this->publicPort = $this->database->public_port;
            $this->isLogDrainEnabled = $this->database->is_log_drain_enabled;
            $this->customDockerRunOptions = $this->database->custom_docker_run_options;
            $this->enableSsl = $this->database->enable_ssl;
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

    public function refresh(): void
    {
        $this->database->refresh();
        $this->syncData();
    }

    public function render()
    {
        return view('livewire.project.database.mariadb.general');
    }
}
