<?php

namespace App\Livewire\Project\Database\Redis;

use App\Actions\Database\StartDatabaseProxy;
use App\Actions\Database\StopDatabaseProxy;
use App\Helpers\SslHelper;
use App\Models\Server;
use App\Models\StandaloneRedis;
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

    public StandaloneRedis $database;

    public string $name;

    public ?string $description = null;

    public ?string $redisConf = null;

    public string $image;

    public ?string $portsMappings = null;

    public ?bool $isPublic = null;

    public mixed $publicPort = null;

    public mixed $publicPortTimeout = 3600;

    public bool $isLogDrainEnabled = false;

    public ?string $customDockerRunOptions = null;

    public string $redisUsername;

    public string $redisPassword;

    public string $redisVersion;

    public ?string $dbUrl = null;

    public ?string $dbUrlPublic = null;

    public bool $enableSsl = false;

    public ?Carbon $certificateValidUntil = null;

    public function getListeners()
    {
        $userId = Auth::id();
        $teamId = Auth::user()->currentTeam()->id;

        return [
            "echo-private:user.{$userId},DatabaseStatusChanged" => 'refresh',
            "echo-private:team.{$teamId},ServiceChecked" => 'refresh',
            'envsUpdated' => 'refresh',
        ];
    }

    protected function rules(): array
    {
        return [
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
            'redisConf' => 'nullable',
            'image' => 'required',
            'portsMappings' => ValidationPatterns::portMappingRules(),
            'isPublic' => 'nullable|boolean',
            'publicPort' => 'nullable|integer|min:1|max:65535',
            'publicPortTimeout' => 'nullable|integer|min:1',
            'isLogDrainEnabled' => 'nullable|boolean',
            'customDockerRunOptions' => 'nullable',
            'redisUsername' => 'required',
            'redisPassword' => 'required',
            'enableSsl' => 'boolean',
        ];
    }

    protected function messages(): array
    {
        return array_merge(
            ValidationPatterns::combinedMessages(),
            ValidationPatterns::portMappingMessages(),
            [
                'name.required' => 'The Name field is required.',
                'image.required' => 'The Docker Image field is required.',
                'publicPort.integer' => 'The Public Port must be an integer.',
                'publicPort.min' => 'The Public Port must be at least 1.',
                'publicPort.max' => 'The Public Port must not exceed 65535.',
                'publicPortTimeout.integer' => 'The Public Port Timeout must be an integer.',
                'publicPortTimeout.min' => 'The Public Port Timeout must be at least 1.',
                'redisUsername.required' => 'The Redis Username field is required.',
                'redisPassword.required' => 'The Redis Password field is required.',
            ]
        );
    }

    protected $validationAttributes = [
        'name' => 'Name',
        'description' => 'Description',
        'redisConf' => 'Redis Configuration',
        'image' => 'Image',
        'portsMappings' => 'Port Mapping',
        'isPublic' => 'Is Public',
        'publicPort' => 'Public Port',
        'publicPortTimeout' => 'Public Port Timeout',
        'customDockerRunOptions' => 'Custom Docker Options',
        'redisUsername' => 'Redis Username',
        'redisPassword' => 'Redis Password',
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
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            $this->validate();
            $this->database->name = $this->name;
            $this->database->description = $this->description;
            $this->database->redis_conf = $this->redisConf;
            $this->database->image = $this->image;
            $this->database->ports_mappings = $this->portsMappings;
            $this->database->is_public = $this->isPublic;
            $this->database->public_port = $this->publicPort ?: null;
            $this->database->public_port_timeout = $this->publicPortTimeout ?: null;
            $this->database->is_log_drain_enabled = $this->isLogDrainEnabled;
            $this->database->custom_docker_run_options = $this->customDockerRunOptions;
            $this->database->enable_ssl = $this->enableSsl;
            $this->database->save();

            $this->dbUrl = $this->database->internal_db_url;
            $this->dbUrlPublic = $this->database->external_db_url;
        } else {
            $this->name = $this->database->name;
            $this->description = $this->database->description;
            $this->redisConf = $this->database->redis_conf;
            $this->image = $this->database->image;
            $this->portsMappings = $this->database->ports_mappings;
            $this->isPublic = $this->database->is_public;
            $this->publicPort = $this->database->public_port;
            $this->publicPortTimeout = $this->database->public_port_timeout;
            $this->isLogDrainEnabled = $this->database->is_log_drain_enabled;
            $this->customDockerRunOptions = $this->database->custom_docker_run_options;
            $this->enableSsl = $this->database->enable_ssl;
            $this->dbUrl = $this->database->internal_db_url;
            $this->dbUrlPublic = $this->database->external_db_url;
            $this->redisVersion = $this->database->getRedisVersion();
            $this->redisUsername = $this->database->redis_username;
            $this->redisPassword = $this->database->redis_password;
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
            $this->authorize('manageEnvironment', $this->database);

            if ($this->portsMappings) {
                $this->portsMappings = str($this->portsMappings)->replace(' ', '')->trim()->toString();
            }
            $this->syncData(true);

            if (version_compare($this->redisVersion, '6.0', '>=')) {
                $this->database->runtime_environment_variables()->updateOrCreate(
                    ['key' => 'REDIS_USERNAME'],
                    ['value' => $this->redisUsername, 'resourceable_id' => $this->database->id]
                );
            }
            $this->database->runtime_environment_variables()->updateOrCreate(
                ['key' => 'REDIS_PASSWORD'],
                ['value' => $this->redisPassword, 'resourceable_id' => $this->database->id]
            );

            $this->dispatch('success', 'Database updated.');
        } catch (Exception $e) {
            return handleError($e, $this);
        } finally {
            $this->dispatch('refreshEnvs');
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

            if (! $caCert) {
                $this->server->generateCaCertificate();
                $caCert = $this->server->sslCertificates()->where('is_ca_certificate', true)->first();
            }

            if (! $caCert) {
                $this->dispatch('error', 'No CA certificate found for this database. Please generate a CA certificate for this server in the server/advanced page.');

                return;
            }

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

            $this->dispatch('success', 'SSL certificates regenerated. Restart database to apply changes.');
        } catch (Exception $e) {
            handleError($e, $this);
        }
    }

    public function refresh(): void
    {
        $this->database->refresh();
        $this->syncData();
    }

    public function render()
    {
        return view('livewire.project.database.redis.general');
    }

    public function isSharedVariable($name)
    {
        return $this->database->runtime_environment_variables()->where('key', $name)->where('is_shared', true)->exists();
    }
}
