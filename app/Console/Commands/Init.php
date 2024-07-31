<?php

namespace App\Console\Commands;

use App\Actions\Server\StopSentinel;
use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\CleanupHelperContainersJob;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Server;
use App\Models\StandalonePostgresql;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class Init extends Command
{
    protected $signature = 'app:init {--full-cleanup} {--cleanup-deployments}';

    protected $description = 'Cleanup instance related stuffs';

    public function handle()
    {
        $this->alive();
        get_public_ips();
        if (version_compare('4.0.0-beta.312', config('version'), '<=')) {
            $servers = Server::all();
            foreach ($servers as $server) {
                $server->settings->update(['is_metrics_enabled' => false]);
                if ($server->isFunctional()) {
                    StopSentinel::dispatch($server);
                }
            }
        }

        $full_cleanup = $this->option('full-cleanup');
        $cleanup_deployments = $this->option('cleanup-deployments');

        $this->replace_slash_in_environment_name();
        if ($cleanup_deployments) {
            echo "Running cleanup deployments.\n";
            $this->cleanup_in_progress_application_deployments();

            return;
        }
        if ($full_cleanup) {
            // Required for falsely deleted coolify db
            $this->restore_coolify_db_backup();
            $this->cleanup_in_progress_application_deployments();
            $this->cleanup_stucked_helper_containers();
            $this->call('cleanup:queue');
            $this->call('cleanup:stucked-resources');
            if (! isCloud()) {
                try {
                    $server = Server::find(0)->first();
                    $server->setupDynamicProxyConfiguration();
                } catch (\Throwable $e) {
                    echo "Could not setup dynamic configuration: {$e->getMessage()}\n";
                }
            }

            $settings = InstanceSettings::get();
            if (! is_null(env('AUTOUPDATE', null))) {
                if (env('AUTOUPDATE') == true) {
                    $settings->update(['is_auto_update_enabled' => true]);
                } else {
                    $settings->update(['is_auto_update_enabled' => false]);
                }
            }

            return;
        }
        $this->cleanup_stucked_helper_containers();
        $this->call('cleanup:stucked-resources');
    }

    private function restore_coolify_db_backup()
    {
        try {
            $database = StandalonePostgresql::withTrashed()->find(0);
            if ($database && $database->trashed()) {
                echo "Restoring coolify db backup\n";
                $database->restore();
                $scheduledBackup = ScheduledDatabaseBackup::find(0);
                if (! $scheduledBackup) {
                    ScheduledDatabaseBackup::create([
                        'id' => 0,
                        'enabled' => true,
                        'save_s3' => false,
                        'frequency' => '0 0 * * *',
                        'database_id' => $database->id,
                        'database_type' => 'App\Models\StandalonePostgresql',
                        'team_id' => 0,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            echo "Error in restoring coolify db backup: {$e->getMessage()}\n";
        }
    }

    private function cleanup_stucked_helper_containers()
    {
        $servers = Server::all();
        foreach ($servers as $server) {
            if ($server->isFunctional()) {
                CleanupHelperContainersJob::dispatch($server);
            }
        }
    }

    private function alive()
    {
        $id = config('app.id');
        $version = config('version');
        $settings = InstanceSettings::get();
        $do_not_track = data_get($settings, 'do_not_track');
        if ($do_not_track == true) {
            echo "Skipping alive as do_not_track is enabled\n";

            return;
        }
        try {
            Http::get("https://undead.coolify.io/v4/alive?appId=$id&version=$version");
            echo "I am alive!\n";
        } catch (\Throwable $e) {
            echo "Error in alive: {$e->getMessage()}\n";
        }
    }
    // private function cleanup_ssh()
    // {

    // TODO: it will cleanup id.root@host.docker.internal
    //     try {
    //         $files = Storage::allFiles('ssh/keys');
    //         foreach ($files as $file) {
    //             Storage::delete($file);
    //         }
    //         $files = Storage::allFiles('ssh/mux');
    //         foreach ($files as $file) {
    //             Storage::delete($file);
    //         }
    //     } catch (\Throwable $e) {
    //         echo "Error in cleaning ssh: {$e->getMessage()}\n";
    //     }
    // }
    private function cleanup_in_progress_application_deployments()
    {
        // Cleanup any failed deployments

        try {
            if (isCloud()) {
                return;
            }
            $queued_inprogress_deployments = ApplicationDeploymentQueue::whereIn('status', [ApplicationDeploymentStatus::IN_PROGRESS->value, ApplicationDeploymentStatus::QUEUED->value])->get();
            foreach ($queued_inprogress_deployments as $deployment) {
                ray($deployment->id, $deployment->status);
                echo "Cleaning up deployment: {$deployment->id}\n";
                $deployment->status = ApplicationDeploymentStatus::FAILED->value;
                $deployment->save();
            }
        } catch (\Throwable $e) {
            echo "Error: {$e->getMessage()}\n";
        }
    }

    private function replace_slash_in_environment_name()
    {
        $environments = Environment::all();
        foreach ($environments as $environment) {
            if (str_contains($environment->name, '/')) {
                $environment->name = str_replace('/', '-', $environment->name);
                $environment->save();
            }
        }
    }
}
