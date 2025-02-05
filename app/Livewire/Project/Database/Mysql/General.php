<?php

namespace App\Livewire\Project\Database\Mysql;

use App\Actions\Database\StartDatabaseProxy;
use App\Actions\Database\StopDatabaseProxy;
use App\Helpers\SslHelper;
use App\Models\Server;
use App\Models\SslCertificate;
use App\Models\StandaloneMysql;
use Exception;
use Livewire\Component;

class General extends Component
{
    protected $listeners = ['refresh'];

    public StandaloneMysql $database;

    public Server $server;

    public ?string $db_url = null;

    public ?string $db_url_public = null;

    public $certificateValidUntil = null;

    protected $rules = [
        'database.name' => 'required',
        'database.description' => 'nullable',
        'database.mysql_root_password' => 'required',
        'database.mysql_user' => 'required',
        'database.mysql_password' => 'required',
        'database.mysql_database' => 'required',
        'database.mysql_conf' => 'nullable',
        'database.image' => 'required',
        'database.ports_mappings' => 'nullable',
        'database.is_public' => 'nullable|boolean',
        'database.public_port' => 'nullable|integer',
        'database.is_log_drain_enabled' => 'nullable|boolean',
        'database.custom_docker_run_options' => 'nullable',
        'database.enable_ssl' => 'boolean',
        'database.ssl_mode' => 'nullable|string|in:PREFERRED,REQUIRED,VERIFY_CA,VERIFY_IDENTITY',
    ];

    protected $validationAttributes = [
        'database.name' => 'Name',
        'database.description' => 'Description',
        'database.mysql_root_password' => 'Root Password',
        'database.mysql_user' => 'User',
        'database.mysql_password' => 'Password',
        'database.mysql_database' => 'Database',
        'database.mysql_conf' => 'MySQL Configuration',
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

        $existingCert = SslCertificate::where('resource_type', $this->database->getMorphClass())
            ->where('resource_id', $this->database->id)
            ->first();

        if ($existingCert) {
            $this->certificateValidUntil = $existingCert->valid_until;
        }
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

    public function instantSaveSSL()
    {
        try {
            $this->database->enable_ssl = $this->database->enable_ssl;
            $this->database->ssl_mode = $this->database->ssl_mode;
            $this->database->save();
            $this->dispatch('success', 'SSL configuration updated.');
        } catch (Exception $e) {
            return handleError($e, $this);
        }
    }

    public function regenerateSslCertificate()
    {
        try {
            $existingCert = SslCertificate::where('resource_type', $this->database->getMorphClass())
                ->where('resource_id', $this->database->id)
                ->where('server_id', $this->server->id)
                ->first();

            if (! $existingCert) {
                $this->dispatch('error', 'No existing SSL certificate found for this database.');

                return;
            }

            $caCertificate = SslCertificate::where('server_id', $this->server->id)
                ->where('resource_type', null)
                ->where('resource_id', null)
                ->first();

            SslHelper::generateSslCertificate(
                commonName: $existingCert->common_name,
                subjectAlternativeNames: $existingCert->subject_alternative_names ?? [],
                resourceType: $existingCert->resource_type,
                resourceId: $existingCert->resource_id,
                serverId: $existingCert->server_id,
                caCert: $caCertificate->ssl_certificate,
                caKey: $caCertificate->ssl_private_key,
                configurationDir: $existingCert->configuration_dir,
                mountPath: '/var/lib/mysql/certs',
            );

            $this->dispatch('success', 'SSL certificates have been regenerated. Please restart the database for changes to take effect.');
        } catch (Exception $e) {
            return handleError($e, $this);
        }
    }

    public function refresh(): void
    {
        $this->database->refresh();
    }

    public function render()
    {
        return view('livewire.project.database.mysql.general');
    }
}
