<?php

namespace App\Console\Commands;

use App\Enums\ActivityTypes;
use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\CheckHelperImageJob;
use App\Jobs\PullChangelog;
use App\Jobs\SyncGithubDeploymentJob;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\ScheduledTaskExecution;
use App\Models\Server;
use App\Models\StandalonePostgresql;
use App\Models\User;
use App\Services\AutoUpdateNotifier;
use App\Support\ResourceStartActivity;
use App\Support\RootSetupWindow;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
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

        self::compressLegacyLogFile(storage_path('logs'));

        try {
            RootSetupWindow::openIfNoUserExists();
        } catch (\Throwable $e) {
            echo "Could not open the root setup window: {$e->getMessage()}\n";
        }

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

        try {
            AutoUpdateNotifier::checkResult();
        } catch (\Throwable $e) {
            echo "Could not check the automatic update result: {$e->getMessage()}\n";
        }

        // Backward compatibility
        $this->replaceSlashInEnvironmentName();
        $this->restoreCoolifyDbBackup();
        $this->updateUserEmails();
        //
        $this->updateTraefikLabels();
        $this->cleanupUnusedNetworkFromCoolifyProxy();

        try {
            $this->call('cleanup:redis', ['--restart' => true, '--clear-locks' => true]);
        } catch (\Throwable $e) {
            echo "Error in cleanup:redis command: {$e->getMessage()}\n";
        }
        try {
            $this->call('cleanup:names');
        } catch (\Throwable $e) {
            echo "Error in cleanup:names command: {$e->getMessage()}\n";
        }
        // Before cleanup:stucked-resources, which removes the helper containers of deployments that are not running.
        try {
            $stuckDeployments = ApplicationDeploymentQueue::whereIn('status', [
                ApplicationDeploymentStatus::IN_PROGRESS->value,
                ApplicationDeploymentStatus::QUEUED->value,
            ])->get(['id', 'application_id', 'parent_deployment_uuid']);
            $updatedCount = ApplicationDeploymentQueue::whereKey($stuckDeployments->modelKeys())->update([
                'status' => ApplicationDeploymentStatus::FAILED->value,
                'finished_at' => Carbon::now(),
            ]);

            if ($updatedCount > 0) {
                echo "Marked {$updatedCount} stuck deployments as failed\n";
            }

            // The query update above fires no model event, so GitHub would show them in progress forever.
            try {
                $stuckDeployments->each(fn (ApplicationDeploymentQueue $deployment) => SyncGithubDeploymentJob::dispatchFor($deployment));
            } catch (\Throwable $e) {
                echo "Could not queue the GitHub deployment sync of stuck deployments: {$e->getMessage()}\n";
            }
        } catch (\Throwable $e) {
            echo "Could not cleanup inprogress deployments: {$e->getMessage()}\n";
        }

        try {
            $this->call('cleanup:stucked-resources');
        } catch (\Throwable $e) {
            echo "Error in cleanup:stucked-resources command: {$e->getMessage()}\n";
            echo "Continuing with initialization - cleanup errors will not prevent Coolify from starting\n";
        }
        try {
            $interruptedStartCount = ResourceStartActivity::failInterrupted();

            if ($interruptedStartCount > 0) {
                echo "Marked {$interruptedStartCount} interrupted database/service starts and database imports as failed\n";
            }
        } catch (\Throwable $e) {
            echo "Could not cleanup interrupted database/service starts and database imports: {$e->getMessage()}\n";
        }

        try {
            $updatedTaskCount = ScheduledTaskExecution::where('status', 'running')->update([
                'status' => 'failed',
                'message' => 'Marked as failed during Coolify startup - job was interrupted',
                'finished_at' => Carbon::now(),
            ]);

            if ($updatedTaskCount > 0) {
                echo "Marked {$updatedTaskCount} stuck scheduled task executions as failed\n";
            }
        } catch (\Throwable $e) {
            echo "Could not cleanup stuck scheduled task executions: {$e->getMessage()}\n";
        }

        try {
            $updatedBackupCount = ScheduledDatabaseBackupExecution::where('status', 'running')->update([
                'status' => 'failed',
                'message' => 'Marked as failed during Coolify startup - job was interrupted',
                'finished_at' => Carbon::now(),
            ]);

            if ($updatedBackupCount > 0) {
                echo "Marked {$updatedBackupCount} stuck database backup executions as failed\n";
            }
        } catch (\Throwable $e) {
            echo "Could not cleanup stuck database backup executions: {$e->getMessage()}\n";
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

    /**
     * Coolify logged to one laravel.log until it moved to daily files, which the daily driver deletes after
     * LOG_DAILY_DAYS. The old file is compressed once, so it stops taking disk space but its lines stay readable.
     * An error is only printed: the startup must go on.
     */
    public static function compressLegacyLogFile(string $directory): void
    {
        $legacyLog = "{$directory}/laravel.log";
        $archive = "{$directory}/laravel-legacy-".now()->format('Ymd-His').'.log.gz';

        try {
            if (! is_file($legacyLog) || is_link($legacyLog)) {
                return;
            }

            if (filesize($legacyLog) > 0) {
                $source = fopen($legacyLog, 'rb');
                $target = gzopen($archive, 'wb6');
                if ($source === false || $target === false) {
                    throw new \RuntimeException("Could not open {$legacyLog} or {$archive}");
                }
                try {
                    while (! feof($source)) {
                        $chunk = fread($source, 1024 * 1024);
                        if ($chunk === false || ($chunk !== '' && gzwrite($target, $chunk) === false)) {
                            throw new \RuntimeException("Could not write {$archive}");
                        }
                    }
                } finally {
                    fclose($source);
                    if (! gzclose($target)) {
                        throw new \RuntimeException("Could not write {$archive}");
                    }
                }
            }

            unlink($legacyLog);
            if (is_file($archive)) {
                echo "Compressed the old log file to {$archive}\n";
            }
        } catch (\Throwable $e) {
            if (is_file($archive)) {
                @unlink($archive);
            }
            echo "Could not compress the old log file {$legacyLog}: {$e->getMessage()}\n";
        }
    }

    private function pullHelperImage()
    {
        CheckHelperImageJob::dispatch();
    }

    private function pullTemplatesFromCDN()
    {
        $url = service_templates_cdn_url();
        if ($url === null) {
            return;
        }
        $response = Http::retry(3, 1000, throw: false)
            ->timeout(60)
            ->connectTimeout(10)
            ->get($url);
        if ($response->successful()) {
            store_service_templates_bundle($response->body());
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
                    $safe = escapeshellarg($network);
                    $out = instant_remote_process(["docker network inspect -f json {$safe} | jq '.[].Containers | if . == {} then null else . end'"], $server, false);
                    if (empty($out)) {
                        $commands->push("docker network disconnect {$safe} coolify-proxy >/dev/null 2>&1 || true");
                        $commands->push("docker network rm {$safe} >/dev/null 2>&1 || true");
                    } else {
                        $data = collect(json_decode($out, true));
                        if ($data->count() === 1) {
                            // If only coolify-proxy itself is connected to that network (it should not be possible, but who knows)
                            $isCoolifyProxyItself = data_get($data->first(), 'Name') === 'coolify-proxy';
                            if ($isCoolifyProxyItself) {
                                $commands->push("docker network disconnect {$safe} coolify-proxy >/dev/null 2>&1 || true");
                                $commands->push("docker network rm {$safe} >/dev/null 2>&1 || true");
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
                    if (! $database->scheduledBackups()->exists()) {
                        ScheduledDatabaseBackup::create([
                            'enabled' => true,
                            'save_s3' => false,
                            'frequency' => '0 0 * * *',
                            'database_id' => $database->id,
                            'database_type' => StandalonePostgresql::class,
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
