<?php

namespace App\Livewire;

use App\Models\InstanceSettings;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Server;
use App\Models\StandalonePostgresql;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;

class SettingsBackup extends Component
{
    public InstanceSettings $settings;

    public ?StandalonePostgresql $database = null;

    public ScheduledDatabaseBackup|null|array $backup = [];

    #[Locked]
    public $s3s;

    #[Locked]
    public $executions = [];

    #[Validate(['required'])]
    public string $uuid;

    #[Validate(['required'])]
    public string $name;

    #[Validate(['nullable'])]
    public ?string $description = null;

    #[Validate(['required'])]
    public string $postgres_user;

    #[Validate(['required'])]
    public string $postgres_password;

    public function mount()
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        } else {
            $settings = instanceSettings();
            $this->database = StandalonePostgresql::whereName('coolify-db')->first();
            $s3s = S3Storage::whereTeamId(0)->get() ?? [];
            if ($this->database) {
                $this->uuid = $this->database->uuid;
                $this->name = $this->database->name;
                $this->description = $this->database->description;
                $this->postgres_user = $this->database->postgres_user;
                $this->postgres_password = $this->database->postgres_password;

                if ($this->database->status !== 'running') {
                    $this->database->status = 'running';
                    $this->database->save();
                }
                $this->backup = $this->database->scheduledBackups->first();
                $this->executions = $this->backup->executions;
            }
            $this->settings = $settings;
            $this->s3s = $s3s;
        }
    }

    public function addCoolifyDatabase()
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
                'destination_type' => \App\Models\StandaloneDocker::class,
                'destination_id' => 0,
            ]);
            $this->backup = ScheduledDatabaseBackup::create([
                'id' => 0,
                'enabled' => true,
                'save_s3' => false,
                'frequency' => '0 0 * * *',
                'database_id' => $this->database->id,
                'database_type' => \App\Models\StandalonePostgresql::class,
                'team_id' => currentTeam()->id,
            ]);
            $this->database->refresh();
            $this->backup->refresh();
            $this->s3s = S3Storage::whereTeamId(0)->get();

            $this->uuid = $this->database->uuid;
            $this->name = $this->database->name;
            $this->description = $this->database->description;
            $this->postgres_user = $this->database->postgres_user;
            $this->postgres_password = $this->database->postgres_password;
            $this->executions = $this->backup->executions;

        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function submit()
    {
        $this->database->update([
            'name' => $this->name,
            'description' => $this->description,
            'postgres_user' => $this->postgres_user,
            'postgres_password' => $this->postgres_password,
        ]);
        $this->dispatch('success', 'Backup updated.');
    }
}
