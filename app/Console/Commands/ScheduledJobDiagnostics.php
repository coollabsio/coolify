<?php

namespace App\Console\Commands;

use App\Jobs\ScheduledJobManager;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledJobDelivery;
use App\Models\ScheduledTask;
use App\Models\ScheduledVolumeBackup;
use App\Models\ServerSetting;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class ScheduledJobDiagnostics extends Command
{
    protected $signature = 'scheduled:diagnostics {--all : List every schedule, not only the overdue ones and the ones without a next run}';

    protected $description = 'Show the scheduler heartbeat, overdue schedules, schedules without a next run, and open deliveries';

    public function handle(): int
    {
        $heartbeat = Cache::get('scheduled-job-manager:heartbeat');
        $heartbeat
            ? $this->info("Scheduler heartbeat: {$heartbeat} (".Carbon::parse($heartbeat)->diffForHumans().')')
            : $this->error('Scheduler heartbeat: missing. The scheduled:dispatch command does not run.');
        $this->newLine();

        $overdueBefore = now()->subMinutes(ScheduledJobManager::LATE_RUN_WINDOW_MINUTES);
        $schedules = [
            'Database backups' => [ScheduledDatabaseBackup::query()->where('enabled', true), 'next_run_at', 'frequency'],
            'Scheduled tasks' => [ScheduledTask::query()->where('enabled', true), 'next_run_at', 'frequency'],
            'Volume backups' => [ScheduledVolumeBackup::query()->where('enabled', true), 'next_run_at', 'frequency'],
            'Docker cleanups' => [ServerSetting::query(), 'docker_cleanup_next_run_at', 'docker_cleanup_frequency'],
        ];

        foreach ($schedules as $label => [$query, $column, $frequencyColumn]) {
            /** @var Builder $query */
            $problems = (clone $query)->where(fn (Builder $query) => $query->whereNull($column)->orWhere($column, '<', $overdueBefore));
            $this->info(sprintf('=== %s: %d enabled, %d overdue or without a next run ===', $label, (clone $query)->count(), (clone $problems)->count()));

            $rows = ($this->option('all') ? $query : $problems)
                ->orderBy($column)
                ->limit(100)
                ->get()
                ->map(fn ($schedule) => [$schedule->getKey(), $schedule->getRawOriginal($frequencyColumn), $schedule->{$column}?->toIso8601String() ?? (next_cron_run_at((string) $schedule->getRawOriginal($frequencyColumn), null, now()) ? 'not calculated' : 'invalid frequency')]);

            if ($rows->isNotEmpty()) {
                $this->table(['ID', 'Frequency', 'Next run (UTC)'], $rows);
            }
            $this->newLine();
        }

        $this->info('=== Open and failed deliveries (failed ones are kept for 30 days) ===');
        $this->table(
            ['Job type', 'Status', 'Count', 'Oldest due (UTC)'],
            ScheduledJobDelivery::query()
                ->whereIn('status', ['pending', 'enqueued', 'claimed', 'failed'])
                ->selectRaw('job_type, status, count(*) as total, min(scheduled_for) as oldest')
                ->groupBy('job_type', 'status')
                ->get()
                ->map(fn ($row) => [$row->job_type, $row->status, $row->total, $row->oldest]),
        );

        return self::SUCCESS;
    }
}
