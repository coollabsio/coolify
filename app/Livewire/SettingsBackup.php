<?php

namespace App\Livewire;

use App\Jobs\DatabaseBackupJob;
use App\Models\InstanceSettings;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Server;
use App\Models\StandalonePostgresql;
use Livewire\Component;

class SettingsBackup extends Component
{
    public InstanceSettings $settings;

    public $s3s;

    public ?StandalonePostgresql $database = null;

    public ScheduledDatabaseBackup|null|array $backup = [];

    public $executions = [];

    protected $rules = [
        'database.uuid' => 'required',
        'database.name' => 'required',
        'database.description' => 'nullable',
        'database.postgres_user' => 'required',
        'database.postgres_password' => 'required',

    ];

    protected $validationAttributes = [
        'database.uuid' => 'uuid',
        'database.name' => 'name',
        'database.description' => 'description',
        'database.postgres_user' => 'postgres user',
        'database.postgres_password' => 'postgres password',
    ];

    public function mount()
    {
        if (isInstanceAdmin()) {
            $settings = InstanceSettings::get();
            $this->database = StandalonePostgresql::whereName('coolify-db')->first();
            $s3s = S3Storage::whereTeamId(0)->get() ?? [];
            if ($this->database) {
                if ($this->database->status !== 'running') {
                    $this->database->status = 'running';
                    $this->database->save();
                }
                $this->backup = $this->database->scheduledBackups->first();
                $this->executions = $this->backup->executions;
            }
            $this->settings = $settings;
            $this->s3s = $s3s;

        } else {
            return redirect()->route('dashboard');
        }
    }

    public function add_coolify_database()
    {
        try {
            $server = Server::findOrFail(0);
            $out = instant_remote_process(['docker inspect coolify-db'], $server);
            $envs = format_docker_envs_to_json($out);
            $postgres_password = $envs['POSTGRES_PASSWORD'];
            $postgres_user = $envs['POSTGRES_USER'];
            $postgres_db = $envs['POSTGRES_DB'];
            $this->database = StandalonePostgresql::create([
                'id' => 0,
                'name' => 'coolify-db',
                'description' => 'Coolify database',
                'postgres_user' => $postgres_user,
                'postgres_password' => $postgres_password,
                'postgres_db' => $postgres_db,
                'status' => 'running',
                'destination_type' => 'App\Models\StandaloneDocker',
                'destination_id' => 0,
            ]);
            $this->backup = ScheduledDatabaseBackup::create([
                'id' => 0,
                'enabled' => true,
                'save_s3' => false,
                'frequency' => '0 0 * * *',
                'database_id' => $this->database->id,
                'database_type' => 'App\Models\StandalonePostgresql',
                'team_id' => currentTeam()->id,
            ]);
            $this->database->refresh();
            $this->backup->refresh();
            $this->s3s = S3Storage::whereTeamId(0)->get();
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function backup_now()
    {
        dispatch(new DatabaseBackupJob(
            backup: $this->backup
        ));
        $this->dispatch('success', 'Backup queued. It will be available in a few minutes.');
    }

    public function submit()
    {
        $this->dispatch('success', 'Backup updated.');
    }
}
