<?php

namespace App\Livewire\Project\Database\Mysql;

use App\Actions\Database\StartDatabaseProxy;
use App\Actions\Database\StopDatabaseProxy;
use App\Helpers\SslHelper;
use App\Models\Server;
use App\Models\StandaloneMysql;
use App\Support\ValidationPatterns;
use Carbon\Carbon;
use Exception;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class General extends Component
{
    use AuthorizesRequests;

    public StandaloneMysql $database;

    public ?Server $server = null;

    public string $name;

    public ?string $description = null;

    public string $mysqlRootPassword;

    public string $mysqlUser;

    public string $mysqlPassword;

    public string $mysqlDatabase;

    public ?string $mysqlConf = null;

    public string $image;

    public ?string $portsMappings = null;

    public ?bool $isPublic = null;

    public ?int $publicPort = null;

    public bool $isLogDrainEnabled = false;

    public ?string $customDockerRunOptions = null;

    public bool $enableSsl = false;

    public ?string $sslMode = null;

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
            'mysqlRootPassword' => 'required',
            'mysqlUser' => 'required',
            'mysqlPassword' => 'required',
            'mysqlDatabase' => 'required',
            'mysqlConf' => 'nullable',
            'image' => 'required',
            'portsMappings' => 'nullable',
            'isPublic' => 'nullable|boolean',
            'publicPort' => 'nullable|integer',
            'isLogDrainEnabled' => 'nullable|boolean',
            'customDockerRunOptions' => 'nullable',
            'enableSsl' => 'boolean',
            'sslMode' => 'nullable|string|in:PREFERRED,REQUIRED,VERIFY_CA,VERIFY_IDENTITY',
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
                'mysqlRootPassword.required' => 'The Root Password field is required.',
                'mysqlUser.required' => 'The MySQL User field is required.',
                'mysqlPassword.required' => 'The MySQL Password field is required.',
                'mysqlDatabase.required' => 'The MySQL Database field is required.',
                'image.required' => 'The Docker Image field is required.',
                'publicPort.integer' => 'The Public Port must be an integer.',
                'sslMode.in' => 'The SSL Mode must be one of: PREFERRED, REQUIRED, VERIFY_CA, VERIFY_IDENTITY.',
            ]
        );
    }

    protected $validationAttributes = [
        'name' => 'Name',
        'description' => 'Description',
        'mysqlRootPassword' => 'Root Password',
        'mysqlUser' => 'User',
        'mysqlPassword' => 'Password',
        'mysqlDatabase' => 'Database',
        'mysqlConf' => 'MySQL Configuration',
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
            $this->database->mysql_root_password = $this->mysqlRootPassword;
            $this->database->mysql_user = $this->mysqlUser;
            $this->database->mysql_password = $this->mysqlPassword;
            $this->database->mysql_database = $this->mysqlDatabase;
            $this->database->mysql_conf = $this->mysqlConf;
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
            $this->mysqlRootPassword = $this->database->mysql_root_password;
            $this->mysqlUser = $this->database->mysql_user;
            $this->mysqlPassword = $this->database->mysql_password;
            $this->mysqlDatabase = $this->database->mysql_database;
            $this->mysqlConf = $this->database->mysql_conf;
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

    public function refresh(): void
    {
        $this->database->refresh();
        $this->syncData();
    }

    public function render()
    {
        return view('livewire.project.database.mysql.general');
    }
}
