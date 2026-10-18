<?php

namespace App\Console\Commands;

use App\Jobs\ServerManagerJob;
use App\Models\DockerCleanupExecution;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledJobDelivery;
use App\Models\ScheduledJobState;
use App\Models\ScheduledTask;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class ScheduledJobDiagnostics extends Command
{
    protected $signature = 'scheduled:diagnostics
        {--type=all : Type to inspect: docker-cleanup, backups, tasks, server-jobs, deliveries, all}
        {--server= : Filter by server ID}';

    protected $description = 'Inspect scheduled job state, due schedules, and open deliveries without changing anything';

    public function handle(): int
    {
        $type = $this->option('type');
        $serverFilter = $this->option('server');

        $this->outputHeartbeat();

        if (in_array($type, ['all', 'docker-cleanup'])) {
            $this->inspectDockerCleanups($serverFilter);
        }

        if (in_array($type, ['all', 'backups'])) {
            $this->inspectBackups();
        }

        if (in_array($type, ['all', 'tasks'])) {
            $this->inspectTasks();
        }

        if (in_array($type, ['all', 'server-jobs'])) {
            $this->inspectServerJobs($serverFilter);
        }

        if (in_array($type, ['all', 'deliveries'])) {
            $this->inspectDeliveries();
        }

        return self::SUCCESS;
    }

    private function outputHeartbeat(): void
    {
        $heartbeat = Cache::get('scheduled-job-manager:heartbeat');
        if ($heartbeat) {
            $age = Carbon::parse($heartbeat)->diffForHumans();
            $this->info("Scheduler heartbeat: {$heartbeat} ({$age})");
        } else {
            $this->error('Scheduler heartbeat: MISSING — ScheduledJobManager may not be running');
        }
        $this->newLine();
    }

    private function inspectDockerCleanups(?string $serverFilter): void
    {
        $this->info('=== Docker Cleanup Jobs ===');

        $servers = $this->getServers($serverFilter);

        $rows = [];
        foreach ($servers as $server) {
            $frequency = data_get($server->settings, 'docker_cleanup_frequency', '0 * * * *');
            if (isset(VALID_CRON_STRINGS[$frequency])) {
                $frequency = VALID_CRON_STRINGS[$frequency];
            }

            $dedupKey = "docker-cleanup:{$server->id}";
            $cacheValue = ScheduledJobState::query()->where('schedule_key', $dedupKey)->value('last_scheduled_for')?->toIso8601String();
            $timezone = data_get($server->settings, 'server_timezone', config('app.timezone'));

            if (validate_timezone($timezone) === false) {
                $timezone = config('app.timezone');
            }

            $wouldFire = shouldRunCronNow($frequency, $timezone);

            $lastExecution = DockerCleanupExecution::where('server_id', $server->id)
                ->latest()
                ->first();

            $rows[] = [
                $server->id,
                $server->name,
                $timezone,
                $frequency,
                $dedupKey,
                $cacheValue ?? 'never',
                $wouldFire ? 'YES' : 'no',
                $lastExecution ? $lastExecution->status.' @ '.$lastExecution->created_at : 'never',
            ];
        }

        $this->table(
            ['ID', 'Server', 'TZ', 'Frequency', 'Schedule Key', 'Last Scheduled For', 'Due Now', 'Last Execution'],
            $rows
        );
        $this->newLine();
    }

    private function inspectBackups(): void
    {
        $this->info('=== Scheduled Backups ===');

        $backups = ScheduledDatabaseBackup::with(['database'])
            ->where('enabled', true)
            ->get();

        $rows = [];
        foreach ($backups as $backup) {
            $server = $backup->server();
            $frequency = $backup->frequency;
            if (isset(VALID_CRON_STRINGS[$frequency])) {
                $frequency = VALID_CRON_STRINGS[$frequency];
            }

            $dedupKey = "scheduled-backup:{$backup->id}";
            $cacheValue = ScheduledJobState::query()->where('schedule_key', $dedupKey)->value('last_scheduled_for')?->toIso8601String();
            $timezone = $server ? data_get($server->settings, 'server_timezone', config('app.timezone')) : config('app.timezone');

            if (validate_timezone($timezone) === false) {
                $timezone = config('app.timezone');
            }

            $wouldFire = shouldRunCronNow($frequency, $timezone);

            $rows[] = [
                $backup->id,
                $backup->database_type ?? 'unknown',
                $server?->name ?? 'N/A',
                $frequency,
                $cacheValue ?? 'never',
                $wouldFire ? 'YES' : 'no',
            ];
        }

        $this->table(
            ['Backup ID', 'DB Type', 'Server', 'Frequency', 'Last Scheduled For', 'Due Now'],
            $rows
        );
        $this->newLine();
    }

    private function inspectTasks(): void
    {
        $this->info('=== Scheduled Tasks ===');

        $tasks = ScheduledTask::with(['service', 'application'])
            ->where('enabled', true)
            ->get();

        $rows = [];
        foreach ($tasks as $task) {
            $server = $task->server();
            $frequency = $task->frequency;
            if (isset(VALID_CRON_STRINGS[$frequency])) {
                $frequency = VALID_CRON_STRINGS[$frequency];
            }

            $dedupKey = "scheduled-task:{$task->id}";
            $cacheValue = ScheduledJobState::query()->where('schedule_key', $dedupKey)->value('last_scheduled_for')?->toIso8601String();
            $timezone = $server ? data_get($server->settings, 'server_timezone', config('app.timezone')) : config('app.timezone');

            if (validate_timezone($timezone) === false) {
                $timezone = config('app.timezone');
            }

            $wouldFire = shouldRunCronNow($frequency, $timezone);

            $rows[] = [
                $task->id,
                $task->name,
                $server?->name ?? 'N/A',
                $frequency,
                $cacheValue ?? 'never',
                $wouldFire ? 'YES' : 'no',
            ];
        }

        $this->table(
            ['Task ID', 'Name', 'Server', 'Frequency', 'Last Scheduled For', 'Due Now'],
            $rows
        );
        $this->newLine();
    }

    private function inspectServerJobs(?string $serverFilter): void
    {
        $this->info('=== Server Manager Jobs ===');

        $servers = $this->getServers($serverFilter);

        $rows = [];
        foreach ($servers as $server) {
            $timezone = data_get($server->settings, 'server_timezone', config('app.timezone'));
            if (validate_timezone($timezone) === false) {
                $timezone = config('app.timezone');
            }

            $dedupKeys = [
                "server-patch-check-v2:{$server->id}" => ServerManagerJob::patchCheckCron($server),
                "sentinel-version-check-v2:{$server->id}" => ServerManagerJob::sentinelVersionCheckCron($server),
                "server-check:{$server->id}" => isCloud() ? '*/5 * * * *' : '* * * * *',
                "server-storage-check:{$server->id}" => data_get($server->settings, 'server_disk_usage_check_frequency', '0 23 * * *'),
            ];

            foreach ($dedupKeys as $dedupKey => $frequency) {
                if (isset(VALID_CRON_STRINGS[$frequency])) {
                    $frequency = VALID_CRON_STRINGS[$frequency];
                }

                $cacheValue = Cache::get($dedupKey);
                $wouldFire = shouldRunCronNow($frequency, $timezone);

                $rows[] = [
                    $server->id,
                    $server->name,
                    $dedupKey,
                    $frequency,
                    $cacheValue ?? '<missing>',
                    $wouldFire ? 'YES' : 'no',
                ];
            }
        }

        $this->table(
            ['Server ID', 'Server', 'Dedup Key', 'Frequency', 'Cache Value', 'Due Now'],
            $rows
        );
        $this->newLine();
    }

    /**
     * Occurrences that are not finished. An old "enqueued" row usually means its queued job was lost.
     */
    private function inspectDeliveries(): void
    {
        $this->info('=== Open and failed deliveries (failed ones are kept for 30 days) ===');

        $this->table(
            ['Job Type', 'Status', 'Count', 'Oldest Due (UTC)'],
            ScheduledJobDelivery::query()
                ->whereIn('status', ['pending', 'enqueued', 'claimed', 'failed'])
                ->selectRaw('job_type, status, count(*) as total, min(scheduled_for) as oldest')
                ->groupBy('job_type', 'status')
                ->get()
                ->map(fn ($row) => [$row->job_type, $row->status, $row->total, $row->oldest])
        );
        $this->newLine();
    }

    private function getServers(?string $serverFilter): Collection
    {
        $query = Server::with('settings')->where('ip', '!=', '1.2.3.4');

        if ($serverFilter) {
            $query->where('id', $serverFilter);
        }

        if (isCloud()) {
            $servers = $query->whereRelation('team.subscription', 'stripe_invoice_paid', true)->get();
            $own = Team::find(0)?->servers()->with('settings')->get() ?? collect();

            return $servers->merge($own);
        }

        return $query->get();
    }
}
