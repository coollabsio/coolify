<?php

namespace App\Livewire\Project\Database\Dragonfly;

use App\Actions\Database\StartDatabaseProxy;
use App\Actions\Database\StopDatabaseProxy;
use App\Helpers\SslHelper;
use App\Models\Server;
use App\Models\StandaloneDragonfly;
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

    public StandaloneDragonfly $database;

    public string $name;

    public ?string $description = null;

    public string $dragonflyPassword;

    public string $image;

    public ?string $portsMappings = null;

    public ?bool $isPublic = null;

    public ?int $publicPort = null;

    public ?string $customDockerRunOptions = null;

    public ?string $dbUrl = null;

    public ?string $dbUrlPublic = null;

    public bool $isLogDrainEnabled = false;

    public ?Carbon $certificateValidUntil = null;

    public bool $enable_ssl = false;

    public function getListeners()
    {
        $userId = Auth::id();
        $teamId = Auth::user()->currentTeam()->id;

        return [
            "echo-private:team.{$teamId},DatabaseProxyStopped" => 'databaseProxyStopped',
            "echo-private:user.{$userId},DatabaseStatusChanged" => '$refresh',
        ];
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

            $existingCert = $this->database->sslCertificates()->first();

            if ($existingCert) {
                $this->certificateValidUntil = $existingCert->valid_until;
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    protected function rules(): array
    {
        return [
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
            'dragonflyPassword' => 'required|string',
            'image' => 'required|string',
            'portsMappings' => 'nullable|string',
            'isPublic' => 'nullable|boolean',
            'publicPort' => 'nullable|integer',
            'customDockerRunOptions' => 'nullable|string',
            'dbUrl' => 'nullable|string',
            'dbUrlPublic' => 'nullable|string',
            'isLogDrainEnabled' => 'nullable|boolean',
            'enable_ssl' => 'nullable|boolean',
        ];
    }

    protected function messages(): array
    {
        return array_merge(
            ValidationPatterns::combinedMessages(),
            [
                'dragonflyPassword.required' => 'The Dragonfly Password field is required.',
                'dragonflyPassword.string' => 'The Dragonfly Password must be a string.',
                'image.required' => 'The Docker Image field is required.',
                'image.string' => 'The Docker Image must be a string.',
                'publicPort.integer' => 'The Public Port must be an integer.',
            ]
        );
    }

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            $this->validate();
            $this->database->name = $this->name;
            $this->database->description = $this->description;
            $this->database->dragonfly_password = $this->dragonflyPassword;
            $this->database->image = $this->image;
            $this->database->ports_mappings = $this->portsMappings;
            $this->database->is_public = $this->isPublic;
            $this->database->public_port = $this->publicPort;
            $this->database->custom_docker_run_options = $this->customDockerRunOptions;
            $this->database->is_log_drain_enabled = $this->isLogDrainEnabled;
            $this->database->enable_ssl = $this->enable_ssl;
            $this->database->save();

            $this->dbUrl = $this->database->internal_db_url;
            $this->dbUrlPublic = $this->database->external_db_url;
        } else {
            $this->name = $this->database->name;
            $this->description = $this->database->description;
            $this->dragonflyPassword = $this->database->dragonfly_password;
            $this->image = $this->database->image;
            $this->portsMappings = $this->database->ports_mappings;
            $this->isPublic = $this->database->is_public;
            $this->publicPort = $this->database->public_port;
            $this->customDockerRunOptions = $this->database->custom_docker_run_options;
            $this->isLogDrainEnabled = $this->database->is_log_drain_enabled;
            $this->enable_ssl = $this->database->enable_ssl;
            $this->dbUrl = $this->database->internal_db_url;
            $this->dbUrlPublic = $this->database->external_db_url;
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
            $this->dbUrlPublic = $this->database->external_db_url;
            $this->syncData(true);
        } catch (\Throwable $e) {
            $this->isPublic = ! $this->isPublic;
            $this->syncData(true);

            return handleError($e, $this);
        }
    }

    public function databaseProxyStopped()
    {
        $this->syncData();
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

            $server = $this->database->destination->server;

            $caCert = $server->sslCertificates()
                ->where('is_ca_certificate', true)
                ->first();

            if (! $caCert) {
                $server->generateCaCertificate();
                $caCert = $server->sslCertificates()->where('is_ca_certificate', true)->first();
            }

            if (! $caCert) {
                $this->dispatch('error', 'No CA certificate found for this database. Please generate a CA certificate for this server in the server/advanced page.');

                return;
            }

            SslHelper::generateSslCertificate(
                commonName: $existingCert->commonName,
                subjectAlternativeNames: $existingCert->subjectAlternativeNames ?? [],
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
}
