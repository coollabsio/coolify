<?php

namespace App\Console\Commands;

use App\Enums\ActivityTypes;
use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\CheckHelperImageJob;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Server;
use App\Models\StandalonePostgresql;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class Init extends Command
{
    protected $signature = 'app:init {--force-cloud}';

    protected $description = 'Cleanup instance related stuffs';

    public $servers = null;

    public function handle()
    {
        $this->optimize();

        if (isCloud() && ! $this->option('force-cloud')) {
            echo "Skipping init as we are on cloud and --force-cloud option is not set\n";

            return;
        }

        $this->servers = Server::all();
        if (! isCloud()) {
            $this->send_alive_signal();
            get_public_ips();
        }

        // Backward compatibility
        $this->replace_slash_in_environment_name();
        $this->restore_coolify_db_backup();
        $this->update_user_emails();
        //
        $this->update_traefik_labels();
        if (! isCloud() || $this->option('force-cloud')) {
            $this->cleanup_unused_network_from_coolify_proxy();
        }
        if (isCloud()) {
            $this->cleanup_unnecessary_dynamic_proxy_configuration();
        } else {
            $this->cleanup_in_progress_application_deployments();
        }
        $this->call('cleanup:redis');

        $this->call('cleanup:stucked-resources');

        try {
            $this->pullHelperImage();
        } catch (\Throwable $e) {
            //
        }

        if (isCloud()) {
            try {
                $this->pullTemplatesFromCDN();
            } catch (\Throwable $e) {
                echo "Could not pull templates from CDN: {$e->getMessage()}\n";
            }
        }

        if (! isCloud()) {
            try {
                $this->pullTemplatesFromCDN();
            } catch (\Throwable $e) {
                echo "Could not pull templates from CDN: {$e->getMessage()}\n";
            }
            try {
                $localhost = $this->servers->where('id', 0)->first();
                $localhost->setupDynamicProxyConfiguration();
            } catch (\Throwable $e) {
                echo "Could not setup dynamic configuration: {$e->getMessage()}\n";
            }
            $settings = instanceSettings();
            if (! is_null(config('constants.coolify.autoupdate', null))) {
                if (config('constants.coolify.autoupdate') == true) {
                    echo "Enabling auto-update\n";
                    $settings->update(['is_auto_update_enabled' => true]);
                } else {
                    echo "Disabling auto-update\n";
                    $settings->update(['is_auto_update_enabled' => false]);
                }
            }
        }
    }

    private function pullHelperImage()
    {
        CheckHelperImageJob::dispatch();
    }

    private function pullTemplatesFromCDN()
    {
        $response = Http::retry(3, 1000)->get(config('constants.services.official'));
        if ($response->successful()) {
            $services = $response->json();
            File::put(base_path('templates/service-templates.json'), json_encode($services));
        }
    }

    private function optimize()
    {
        Artisan::call('optimize:clear');
        Artisan::call('optimize');
    }

    private function update_user_emails()
    {
        try {
            User::whereRaw('email ~ \'[A-Z]\'')->get()->each(function (User $user) {
                $user->update(['email' => strtolower($user->email)]);
            });
        } catch (\Throwable $e) {
            echo "Error in updating user emails: {$e->getMessage()}\n";
        }
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

    private function cleanup_unused_network_from_coolify_proxy()
    {
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
                    remote_process(command: $commands, type: ActivityTypes::INLINE->value, server: $server, ignore_errors: false);
                }
            } catch (\Throwable $e) {
                echo "Error in cleaning up unused networks from coolify proxy: {$e->getMessage()}\n";
            }
        }
    }

    private function restore_coolify_db_backup()
    {
        if (version_compare('4.0.0-beta.179', config('constants.coolify.version'), '<=')) {
            try {
                $database = StandalonePostgresql::withTrashed()->find(0);
                if ($database && $database->trashed()) {
                    $database->restore();
                    $scheduledBackup = ScheduledDatabaseBackup::find(0);
                    if (! $scheduledBackup) {
                        ScheduledDatabaseBackup::create([
                            'id' => 0,
                            'enabled' => true,
                            'save_s3' => false,
                            'frequency' => '0 0 * * *',
                            'database_id' => $database->id,
                            'database_type' => \App\Models\StandalonePostgresql::class,
                            'team_id' => 0,
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                echo "Error in restoring coolify db backup: {$e->getMessage()}\n";
            }
        }
    }

    private function send_alive_signal()
    {
        $id = config('app.id');
        $version = config('constants.coolify.version');
        $settings = instanceSettings();
        $do_not_track = data_get($settings, 'do_not_track');
        if ($do_not_track == true) {
            echo "Do_not_track is enabled\n";

            return;
        }
        try {
            Http::get("https://undead.coolify.io/v4/alive?appId=$id&version=$version");
        } catch (\Throwable $e) {
            echo "Error in sending live signal: {$e->getMessage()}\n";
        }
    }

    private function cleanup_in_progress_application_deployments()
    {
        // Cleanup any failed deployments
        try {
            if (isCloud()) {
                return;
            }
            $queued_inprogress_deployments = ApplicationDeploymentQueue::whereIn('status', [ApplicationDeploymentStatus::IN_PROGRESS->value, ApplicationDeploymentStatus::QUEUED->value])->get();
            foreach ($queued_inprogress_deployments as $deployment) {
                $deployment->status = ApplicationDeploymentStatus::FAILED->value;
                $deployment->save();
            }
        } catch (\Throwable $e) {
            echo "Error: {$e->getMessage()}\n";
        }
    }

    private function replace_slash_in_environment_name()
    {
        if (version_compare('4.0.0-beta.298', config('constants.coolify.version'), '<=')) {
            $environments = Environment::all();
            foreach ($environments as $environment) {
                if (str_contains($environment->name, '/')) {
                    $environment->name = str_replace('/', '-', $environment->name);
                    $environment->save();
                }
            }
        }
    }
}
