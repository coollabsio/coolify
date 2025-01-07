<?php

namespace App\Livewire\Project\Database\Clickhouse;

use App\Actions\Database\StartDatabaseProxy;
use App\Actions\Database\StopDatabaseProxy;
use App\Models\Server;
use App\Models\StandaloneClickhouse;
use Exception;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Validate;
use Livewire\Component;

class General extends Component
{
    public Server $server;

    public StandaloneClickhouse $database;

    #[Validate(['required', 'string'])]
    public string $name;

    #[Validate(['nullable', 'string'])]
    public ?string $description = null;

    #[Validate(['required', 'string'])]
    public string $clickhouseAdminUser;

    #[Validate(['required', 'string'])]
    public string $clickhouseAdminPassword;

    #[Validate(['required', 'string'])]
    public string $image;

    #[Validate(['nullable', 'string'])]
    public ?string $portsMappings = null;

    #[Validate(['nullable', 'boolean'])]
    public ?bool $isPublic = null;

    #[Validate(['nullable', 'integer'])]
    public ?int $publicPort = null;

    #[Validate(['nullable', 'string'])]
    public ?string $customDockerRunOptions = null;

    #[Validate(['nullable', 'string'])]
    public ?string $dbUrl = null;

    #[Validate(['nullable', 'string'])]
    public ?string $dbUrlPublic = null;

    #[Validate(['nullable', 'boolean'])]
    public bool $isLogDrainEnabled = false;

    public function getListeners()
    {
        $teamId = Auth::user()->currentTeam()->id;

        return [
            "echo-private:team.{$teamId},DatabaseProxyStopped" => 'databaseProxyStopped',
        ];
    }

    public function mount()
    {
        try {
            $this->syncData();
            $this->server = data_get($this->database, 'destination.server');
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
            $this->database->clickhouse_admin_user = $this->clickhouseAdminUser;
            $this->database->clickhouse_admin_password = $this->clickhouseAdminPassword;
            $this->database->image = $this->image;
            $this->database->ports_mappings = $this->portsMappings;
            $this->database->is_public = $this->isPublic;
            $this->database->public_port = $this->publicPort;
            $this->database->custom_docker_run_options = $this->customDockerRunOptions;
            $this->database->is_log_drain_enabled = $this->isLogDrainEnabled;
            $this->database->save();

            $this->dbUrl = $this->database->internal_db_url;
            $this->dbUrlPublic = $this->database->external_db_url;
        } else {
            $this->name = $this->database->name;
            $this->description = $this->database->description;
            $this->clickhouseAdminUser = $this->database->clickhouse_admin_user;
            $this->clickhouseAdminPassword = $this->database->clickhouse_admin_password;
            $this->image = $this->database->image;
            $this->portsMappings = $this->database->ports_mappings;
            $this->isPublic = $this->database->is_public;
            $this->publicPort = $this->database->public_port;
            $this->customDockerRunOptions = $this->database->custom_docker_run_options;
            $this->isLogDrainEnabled = $this->database->is_log_drain_enabled;
            $this->dbUrl = $this->database->internal_db_url;
            $this->dbUrlPublic = $this->database->external_db_url;
        }
    }

    public function instantSaveAdvanced()
    {
        try {
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
