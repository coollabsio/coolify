<?php

namespace App\Livewire\Project\Database\Mariadb;

use App\Actions\Database\StartDatabaseProxy;
use App\Actions\Database\StopDatabaseProxy;
use App\Helpers\SslHelper;
use App\Models\Server;
use App\Models\SslCertificate;
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

    protected $listeners = ['refresh'];

    public Server $server;

    public StandaloneMariadb $database;

    public ?string $db_url = null;

    public ?string $db_url_public = null;

    public ?Carbon $certificateValidUntil = null;

    public function getListeners()
    {
        $userId = Auth::id();

        return [
            "echo-private:user.{$userId},DatabaseStatusChanged" => '$refresh',
            'refresh' => '$refresh',
        ];
    }

    protected function rules(): array
    {
        return [
            'database.name' => ValidationPatterns::nameRules(),
            'database.description' => ValidationPatterns::descriptionRules(),
            'database.mariadb_root_password' => 'required',
            'database.mariadb_user' => 'required',
            'database.mariadb_password' => 'required',
            'database.mariadb_database' => 'required',
            'database.mariadb_conf' => 'nullable',
            'database.image' => 'required',
            'database.ports_mappings' => 'nullable',
            'database.is_public' => 'nullable|boolean',
            'database.public_port' => 'nullable|integer',
            'database.is_log_drain_enabled' => 'nullable|boolean',
            'database.custom_docker_run_options' => 'nullable',
            'database.enable_ssl' => 'boolean',
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
                'database.mariadb_root_password.required' => 'The Root Password field is required.',
                'database.mariadb_user.required' => 'The MariaDB User field is required.',
                'database.mariadb_password.required' => 'The MariaDB Password field is required.',
                'database.mariadb_database.required' => 'The MariaDB Database field is required.',
                'database.image.required' => 'The Docker Image field is required.',
                'database.public_port.integer' => 'The Public Port must be an integer.',
            ]
        );
    }

    protected $validationAttributes = [
        'database.name' => 'Name',
        'database.description' => 'Description',
        'database.mariadb_root_password' => 'Root Password',
        'database.mariadb_user' => 'User',
        'database.mariadb_password' => 'Password',
        'database.mariadb_database' => 'Database',
        'database.mariadb_conf' => 'MariaDB Configuration',
        'database.image' => 'Image',
        'database.ports_mappings' => 'Port Mapping',
        'database.is_public' => 'Is Public',
        'database.public_port' => 'Public Port',
        'database.custom_docker_run_options' => 'Custom Docker Options',
        'database.enable_ssl' => 'Enable SSL',
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

    public function instantSaveSSL()
    {
        try {
            $this->authorize('update', $this->database);

            $this->database->save();
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

    public function refresh(): void
    {
        $this->database->refresh();
    }

    public function render()
    {
        return view('livewire.project.database.mariadb.general');
    }
}
