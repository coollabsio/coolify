<?php

namespace App\Console\Commands;

use App\Enums\ActivityTypes;
use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\CheckHelperImageJob;
use App\Jobs\PullChangelog;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
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
    protected $signature = 'app:init';

    protected $description = 'Cleanup instance related stuffs';

    public $servers = null;

    public InstanceSettings $settings;

    public function handle()
    {
        Artisan::call('optimize:clear');
        Artisan::call('optimize');

        try {
            $this->pullTemplatesFromCDN();
        } catch (\Throwable $e) {
            echo "Could not pull templates from CDN: {$e->getMessage()}\n";
        }

        try {
            $this->pullChangelogFromGitHub();
        } catch (\Throwable $e) {
            echo "Could not changelogs from github: {$e->getMessage()}\n";
        }

        try {
            $this->pullHelperImage();
        } catch (\Throwable $e) {
            echo "Error in pullHelperImage command: {$e->getMessage()}\n";
        }

        if (isCloud()) {
            return;
        }

        $this->settings = instanceSettings();
        $this->servers = Server::all();

        $do_not_track = data_get($this->settings, 'do_not_track', true);
        if ($do_not_track == false) {
            $this->sendAliveSignal();
        }
        get_public_ips();

        // Backward compatibility
        $this->replaceSlashInEnvironmentName();
        $this->restoreCoolifyDbBackup();
        $this->updateUserEmails();
        //
        $this->updateTraefikLabels();
        $this->cleanupUnusedNetworkFromCoolifyProxy();

        try {
            $this->call('cleanup:redis');
        } catch (\Throwable $e) {
            echo "Error in cleanup:redis command: {$e->getMessage()}\n";
        }
        try {
            $this->call('cleanup:names');
        } catch (\Throwable $e) {
            echo "Error in cleanup:names command: {$e->getMessage()}\n";
        }
        try {
            $this->call('cleanup:stucked-resources');
        } catch (\Throwable $e) {
            echo "Error in cleanup:stucked-resources command: {$e->getMessage()}\n";
        }
        try {
            $updatedCount = ApplicationDeploymentQueue::whereIn('status', [
                ApplicationDeploymentStatus::IN_PROGRESS->value,
                ApplicationDeploymentStatus::QUEUED->value,
            ])->update([
                'status' => ApplicationDeploymentStatus::FAILED->value,
            ]);

            if ($updatedCount > 0) {
                echo "Marked {$updatedCount} stuck deployments as failed\n";
            }
        } catch (\Throwable $e) {
            echo "Could not cleanup inprogress deployments: {$e->getMessage()}\n";
        }

        try {
            $localhost = $this->servers->where('id', 0)->first();
            if ($localhost) {
                $localhost->setupDynamicProxyConfiguration();
            }
        } catch (\Throwable $e) {
            echo "Could not setup dynamic configuration: {$e->getMessage()}\n";
        }

        if (! is_null(config('constants.coolify.autoupdate', null))) {
            if (config('constants.coolify.autoupdate') == true) {
                echo "Enabling auto-update\n";
                $this->settings->update(['is_auto_update_enabled' => true]);
            } else {
                echo "Disabling auto-update\n";
                $this->settings->update(['is_auto_update_enabled' => false]);
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
            File::put(base_path('templates/'.config('constants.services.file_name')), json_encode($services));
        }
    }

    private function pullChangelogFromGitHub()
    {
        try {
            PullChangelog::dispatch();
            echo "Changelog fetch initiated\n";
        } catch (\Throwable $e) {
            echo "Could not fetch changelog from GitHub: {$e->getMessage()}\n";
        }
    }

    private function updateUserEmails()
    {
        try {
            User::whereRaw('email ~ \'[A-Z]\'')->get()->each(function (User $user) {
                $user->update(['email' => $user->email]);
            });
        } catch (\Throwable $e) {
            echo "Error in updating user emails: {$e->getMessage()}\n";
        }
    }

    private function updateTraefikLabels()
    {
        try {
            Server::where('proxy->type', 'TRAEFIK_V2')->update(['proxy->type' => 'TRAEFIK']);
        } catch (\Throwable $e) {
            echo "Error in updating traefik labels: {$e->getMessage()}\n";
        }
    }

    private function cleanupUnusedNetworkFromCoolifyProxy()
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

    private function restoreCoolifyDbBackup()
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

    private function sendAliveSignal()
    {
        $id = config('app.id');
        $version = config('constants.coolify.version');
        try {
            Http::get("https://undead.coolify.io/v4/alive?appId=$id&version=$version");
        } catch (\Throwable $e) {
            echo "Error in sending live signal: {$e->getMessage()}\n";
        }
    }

    private function replaceSlashInEnvironmentName()
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
