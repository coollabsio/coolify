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
        echo "Starting handle()\n";
        $this->optimize();

        if (isCloud() && ! $this->option('force-cloud')) {
            echo "Skipping init as we are on cloud and --force-cloud option is not set\n";

            return;
        }

        echo "Loading servers...\n";
        $this->servers = Server::all();
        if (isCloud()) {
            echo "Running in cloud mode\n";
        } else {
            echo "Running in self-hosted mode\n";
            $this->send_alive_signal();
            get_public_ips();
        }

        echo "Starting backward compatibility checks...\n";
        // Backward compatibility
        $this->replace_slash_in_environment_name();
        $this->restore_coolify_db_backup();
        $this->update_user_emails();
        //
        $this->update_traefik_labels();
        if (! isCloud() || $this->option('force-cloud')) {
            echo "Cleaning up unused networks...\n";
            $this->cleanup_unused_network_from_coolify_proxy();
        }
        if (isCloud()) {
            echo "Cleaning up unnecessary proxy configs...\n";
            $this->cleanup_unnecessary_dynamic_proxy_configuration();
        } else {
            echo "Cleaning up in-progress deployments...\n";
            $this->cleanup_in_progress_application_deployments();
        }
        echo "Running redis cleanup...\n";
        $this->call('cleanup:redis');

        echo "Running stucked resources cleanup...\n";
        $this->call('cleanup:stucked-resources');

        try {
            echo "Pulling helper image...\n";
            $this->pullHelperImage();
        } catch (\Throwable $e) {
            echo "Error pulling helper image: {$e->getMessage()}\n";
        }

        if (isCloud()) {
            try {
                echo "Pulling templates from CDN (cloud mode)...\n";
                $this->pullTemplatesFromCDN();
            } catch (\Throwable $e) {
                echo "Could not pull templates from CDN: {$e->getMessage()}\n";
            }
        }

        if (! isCloud()) {
            try {
                echo "Pulling templates from CDN (self-hosted mode)...\n";
                $this->pullTemplatesFromCDN();
            } catch (\Throwable $e) {
                echo "Could not pull templates from CDN: {$e->getMessage()}\n";
            }
            try {
                echo "Setting up localhost proxy config...\n";
                $localhost = $this->servers->where('id', 0)->first();
                $localhost->setupDynamicProxyConfiguration();
            } catch (\Throwable $e) {
                echo "Could not setup dynamic configuration: {$e->getMessage()}\n";
            }
            echo "Checking auto-update settings...\n";
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
        echo "handle() complete\n";
    }

    private function pullHelperImage()
    {
        echo "Dispatching CheckHelperImageJob\n";
        CheckHelperImageJob::dispatch();
    }

    private function pullTemplatesFromCDN()
    {
        echo 'Pulling templates from '.config('constants.services.official')."\n";
        $response = Http::retry(3, 1000)->get(config('constants.services.official'));
        if ($response->successful()) {
            echo "Successfully pulled templates\n";
            $services = $response->json();
            File::put(base_path('templates/service-templates.json'), json_encode($services));
        }
    }

    private function optimize()
    {
        echo "Running optimize:clear\n";
        Artisan::call('optimize:clear');
        echo "Running optimize\n";
        Artisan::call('optimize');
    }

    private function update_user_emails()
    {
        echo "Starting user email updates...\n";
        try {
            User::whereRaw('email ~ \'[A-Z]\'')->get()->each(function (User $user) {
                echo "Converting email to lowercase: {$user->email}\n";
                $user->update(['email' => strtolower($user->email)]);
            });
        } catch (\Throwable $e) {
            echo "Error in updating user emails: {$e->getMessage()}\n";
        }
    }

    private function update_traefik_labels()
    {
        echo "Updating traefik labels...\n";
        try {
            Server::where('proxy->type', 'TRAEFIK_V2')->update(['proxy->type' => 'TRAEFIK']);
            echo "Traefik labels updated successfully\n";
        } catch (\Throwable $e) {
            echo "Error in updating traefik labels: {$e->getMessage()}\n";
        }
    }

    private function cleanup_unnecessary_dynamic_proxy_configuration()
    {
        echo "Starting cleanup of unnecessary proxy configs...\n";
        foreach ($this->servers as $server) {
            try {
                if (! $server->isFunctional()) {
                    echo "Server {$server->id} not functional, skipping\n";

                    continue;
                }
                if ($server->id === 0) {
                    echo "Skipping localhost server\n";

                    continue;
                }
                $file = $server->proxyPath().'/dynamic/coolify.yaml';
                echo "Removing file: $file\n";

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
        echo "Starting cleanup of unused networks...\n";
        foreach ($this->servers as $server) {
            if (! $server->isFunctional()) {
                echo "Server {$server->id} not functional, skipping\n";

                continue;
            }
            if (! $server->isProxyShouldRun()) {
                echo "Proxy should not run on server {$server->id}, skipping\n";

                continue;
            }
            try {
                echo "Collecting docker networks for server {$server->id}\n";
                ['networks' => $networks, 'allNetworks' => $allNetworks] = collectDockerNetworksByServer($server);
                $removeNetworks = $allNetworks->diff($networks);
                $commands = collect();
                foreach ($removeNetworks as $network) {
                    echo "Checking network: $network\n";
                    $out = instant_remote_process(["docker network inspect -f json $network | jq '.[].Containers | if . == {} then null else . end'"], $server, false);
                    if (empty($out)) {
                        echo "Network $network is empty, marking for removal\n";
                        $commands->push("docker network disconnect $network coolify-proxy >/dev/null 2>&1 || true");
                        $commands->push("docker network rm $network >/dev/null 2>&1 || true");
                    } else {
                        $data = collect(json_decode($out, true));
                        if ($data->count() === 1) {
                            // If only coolify-proxy itself is connected to that network (it should not be possible, but who knows)
                            $isCoolifyProxyItself = data_get($data->first(), 'Name') === 'coolify-proxy';
                            if ($isCoolifyProxyItself) {
                                echo "Network $network only has coolify-proxy, marking for removal\n";
                                $commands->push("docker network disconnect $network coolify-proxy >/dev/null 2>&1 || true");
                                $commands->push("docker network rm $network >/dev/null 2>&1 || true");
                            }
                        }
                    }
                }
                if ($commands->isNotEmpty()) {
                    echo "Executing network cleanup commands\n";
                    remote_process(command: $commands, type: ActivityTypes::INLINE->value, server: $server, ignore_errors: false);
                }
            } catch (\Throwable $e) {
                echo "Error in cleaning up unused networks from coolify proxy: {$e->getMessage()}\n";
            }
        }
    }

    private function restore_coolify_db_backup()
    {
        echo "Checking if DB backup restore is needed...\n";
        if (version_compare('4.0.0-beta.179', config('constants.coolify.version'), '<=')) {
            try {
                $database = StandalonePostgresql::withTrashed()->find(0);
                if ($database && $database->trashed()) {
                    echo "Restoring coolify db backup\n";
                    $database->restore();
                    $scheduledBackup = ScheduledDatabaseBackup::find(0);
                    if (! $scheduledBackup) {
                        echo "Creating scheduled backup\n";
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
        echo "Sending alive signal...\n";
        $id = config('app.id');
        $version = config('constants.coolify.version');
        $settings = instanceSettings();
        $do_not_track = data_get($settings, 'do_not_track');
        if ($do_not_track == true) {
            echo "Do_not_track is enabled\n";

            return;
        }
        try {
            echo "Sending request to undead.coolify.io\n";
            Http::get("https://undead.coolify.io/v4/alive?appId=$id&version=$version");
        } catch (\Throwable $e) {
            echo "Error in sending live signal: {$e->getMessage()}\n";
        }
    }

    private function cleanup_in_progress_application_deployments()
    {
        echo "Starting cleanup of in-progress deployments...\n";
        // Cleanup any failed deployments
        try {
            if (isCloud()) {
                echo "Skipping cleanup in cloud mode\n";

                return;
            }
            echo "Finding queued/in-progress deployments...\n";
            $queued_inprogress_deployments = ApplicationDeploymentQueue::whereIn('status', [ApplicationDeploymentStatus::IN_PROGRESS->value, ApplicationDeploymentStatus::QUEUED->value])->get();
            foreach ($queued_inprogress_deployments as $deployment) {
                echo "Marking deployment {$deployment->id} as failed\n";
                $deployment->status = ApplicationDeploymentStatus::FAILED->value;
                $deployment->save();
            }
        } catch (\Throwable $e) {
            echo "Error: {$e->getMessage()}\n";
        }
    }

    private function replace_slash_in_environment_name()
    {
        echo "Checking for slashes in environment names...\n";
        if (version_compare('4.0.0-beta.298', config('constants.coolify.version'), '<=')) {
            $environments = Environment::all();
            foreach ($environments as $environment) {
                if (str_contains($environment->name, '/')) {
                    echo "Replacing slashes in environment: {$environment->name}\n";
                    $environment->name = str_replace('/', '-', $environment->name);
                    $environment->save();
                }
            }
        }
    }
}
