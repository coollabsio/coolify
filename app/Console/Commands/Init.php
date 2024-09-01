<?php

namespace App\Console\Commands;

use App\Actions\Server\StopSentinel;
use App\Enums\ActivityTypes;
use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\CleanupHelperContainersJob;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Server;
use App\Models\StandalonePostgresql;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class Init extends Command
{
    protected $signature = 'app:init {--full-cleanup} {--cleanup-deployments} {--cleanup-proxy-networks}';

    protected $description = 'Cleanup instance related stuffs';

    public $servers = null;

    public function handle()
    {
        $this->servers = Server::all();
        $this->alive();
        get_public_ips();
        if (version_compare('4.0.0-beta.312', config('version'), '<=')) {
            foreach ($this->servers as $server) {
                if ($server->settings->is_metrics_enabled === true) {
                    $server->settings->update(['is_metrics_enabled' => false]);
                }
                if ($server->isFunctional()) {
                    StopSentinel::dispatch($server);
                }
            }
        }

        $full_cleanup = $this->option('full-cleanup');
        $cleanup_deployments = $this->option('cleanup-deployments');
        $cleanup_proxy_networks = $this->option('cleanup-proxy-networks');
        $this->replace_slash_in_environment_name();
        if ($cleanup_deployments) {
            echo "Running cleanup deployments.\n";
            $this->cleanup_in_progress_application_deployments();

            return;
        }
        if ($cleanup_proxy_networks) {
            echo "Running cleanup proxy networks.\n";
            $this->cleanup_unused_network_from_coolify_proxy();

            return;
        }
        if ($full_cleanup) {
            // Required for falsely deleted coolify db
            $this->restore_coolify_db_backup();
            $this->update_traefik_labels();
            $this->cleanup_unused_network_from_coolify_proxy();
            $this->cleanup_unnecessary_dynamic_proxy_configuration();
            $this->cleanup_in_progress_application_deployments();
            $this->cleanup_stucked_helper_containers();
            $this->call('cleanup:queue');
            $this->call('cleanup:stucked-resources');
            if (! isCloud()) {
                try {
                    $localhost = $this->servers->where('id', 0)->first();
                    $localhost->setupDynamicProxyConfiguration();
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
            if (isCloud()) {
                $response = Http::retry(3, 1000)->get(config('constants.services.official'));
                if ($response->successful()) {
                    $services = $response->json();
                    File::put(base_path('templates/service-templates.json'), json_encode($services));
                }
            }

            return;
        }
        $this->cleanup_stucked_helper_containers();
        $this->call('cleanup:stucked-resources');
    }

    private function update_traefik_labels()
    {
        try {
            Server::where('proxy->type', 'TRAEFIK_V2')->update(['proxy->type' => 'TRAEFIK']);
        } catch (\Throwable $e) {
            echo "Error in updating traefik labels: {$e->getMessage()}\n";
        }
    }

    private function cleanup_unnecessary_dynamic_proxy_configuration()
    {
        if (isCloud()) {
            foreach ($this->servers as $server) {
                try {
                    if (! $server->isFunctional()) {
                        continue;
                    }
                    if ($server->id === 0) {
                        continue;
                    }
                    $file = $server->proxyPath().'/dynamic/coolify.yaml';

                    return instant_remote_process([
                        "rm -f $file",
                    ], $server, false);
                } catch (\Throwable $e) {
                    echo "Error in cleaning up unnecessary dynamic proxy configuration: {$e->getMessage()}\n";
                }

            }
        }
    }

    private function cleanup_unused_network_from_coolify_proxy()
    {
        if (isCloud()) {
            return;
        }
        foreach ($this->servers as $server) {
            if (! $server->isFunctional()) {
                continue;
            }
            if (! $server->isProxyShouldRun()) {
                continue;
            }
            try {
                ['networks' => $networks, 'allNetworks' => $allNetworks] = collectDockerNetworksByServer($server);
                $removeNetworks = $allNetworks->diff($networks);
                $commands = collect();
                foreach ($removeNetworks as $network) {
                    $out = instant_remote_process(["docker network inspect -f json $network | jq '.[].Containers | if . == {} then null else . end'"], $server, false);
                    if (empty($out)) {
                        $commands->push("docker network disconnect $network coolify-proxy >/dev/null 2>&1 || true");
                        $commands->push("docker network rm $network >/dev/null 2>&1 || true");
                    } else {
                        $data = collect(json_decode($out, true));
                        if ($data->count() === 1) {
                            // If only coolify-proxy itself is connected to that network (it should not be possible, but who knows)
                            $isCoolifyProxyItself = data_get($data->first(), 'Name') === 'coolify-proxy';
                            if ($isCoolifyProxyItself) {
                                $commands->push("docker network disconnect $network coolify-proxy >/dev/null 2>&1 || true");
                                $commands->push("docker network rm $network >/dev/null 2>&1 || true");
                            }
                        }
                    }
                }
                if ($commands->isNotEmpty()) {
                    echo "Cleaning up unused networks from coolify proxy\n";
                    remote_process(command: $commands, type: ActivityTypes::INLINE->value, server: $server, ignore_errors: false);
                }
            } catch (\Throwable $e) {
                echo "Error in cleaning up unused networks from coolify proxy: {$e->getMessage()}\n";
            }
        }
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
        foreach ($this->servers as $server) {
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
