<?php

namespace App\Http\Livewire\Settings;

use App\Models\InstanceSettings;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Server;
use App\Models\StandalonePostgresql;
use App\Jobs\DatabaseBackupJob;
use Livewire\Component;

class Backup extends Component
{
    public InstanceSettings $settings;
    public $s3s;
    public StandalonePostgresql|null $database = null;
    public ScheduledDatabaseBackup|null $backup = null;

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

    public function add_coolify_database()
    {
        ray('add_coolify_database');
        $server = Server::find(0);
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
            'team_id' => auth()->user()->currentTeam()->id,
        ]);
        $this->database->refresh();
        $this->backup->refresh();
        ray($this->backup);
        $this->s3s = S3Storage::whereTeamId(0)->get();
    }

    public function backup_now() {
        dispatch(new DatabaseBackupJob(
            backup: $this->backup
        ));
        $this->emit('success', 'Backup queued. It will be available in a few minutes');
    }
    public function submit()
    {
        $this->emit('success', 'Backup updated successfully');
    }

}
