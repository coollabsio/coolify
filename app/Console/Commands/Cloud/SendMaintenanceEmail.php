<?php

namespace App\Console\Commands\Cloud;

use App\Models\User;
use App\Notifications\TransactionalEmails\MaintenanceNotice;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendMaintenanceEmail extends Command
{
    protected $signature = 'cloud:send-maintenance-email
        {--maintenance-at= : Required. Maintenance window start in Central European time (Europe/Budapest), e.g. "2026-04-29 22:00".}
        {--throttle-per-minute=1000 : Maximum emails dispatched per minute. Used to stay under rate limits.}
        {--test-to= : Send three test emails (active, inactive, active+inactive-team variants) to this address. Bypasses confirmations.}
        {--dry-run : Only report what would happen, do not send anything}';

    protected $description = 'Send the v5 maintenance notification email to all Cloud users. Run 30 days, 7 days, and ~5h before the maintenance window.';

    public function handle(): int
    {
        if (! isDev() && ! isCloud()) {
            $this->error('This command can only be run on Coolify Cloud.');

            return 1;
        }

        $maintenanceAtInput = $this->option('maintenance-at');
        if (! $maintenanceAtInput) {
            $this->error('--maintenance-at is required, e.g. --maintenance-at="2026-04-29 22:00".');

            return 1;
        }

        try {
            $maintenanceAt = Carbon::parse($maintenanceAtInput, 'Europe/Budapest')->setTimezone('UTC');
        } catch (\Throwable $e) {
            $this->error('Could not parse --maintenance-at: '.$e->getMessage());

            return 1;
        }

        if ($testTo = $this->option('test-to')) {
            return $this->sendTestEmails($testTo, $maintenanceAt);
        }

        $throttlePerMinute = (int) $this->option('throttle-per-minute');
        if ($throttlePerMinute < 1) {
            $this->error('--throttle-per-minute must be at least 1.');

            return 1;
        }

        $dryRun = (bool) $this->option('dry-run');

        $totalUsers = User::query()->where('id', '!=', 0)->whereNotNull('email')->count();
        $estimatedMinutes = (int) ceil($totalUsers / $throttlePerMinute);

        $this->info('Coolify Cloud — v5 maintenance notification');
        $this->line('  Maintenance window : '.$maintenanceAt->copy()->setTimezone('Europe/Budapest')->format('Y-m-d H:i').' CET/CEST  ('.$maintenanceAt->format('Y-m-d H:i').' UTC)');
        $this->line('  Recipients         : '.$totalUsers);
        $this->line('  Throttle           : '.$throttlePerMinute.'/min  (~'.$estimatedMinutes.' min to drain queue)');
        if ($dryRun) {
            $this->warn('  Dry run            : YES (no emails will be sent)');
        }
        $this->newLine();

        if (! $this->confirm('Have you run `cloud:mark-inactive` recently? Inactive flags must be up to date before sending.', false)) {
            $this->warn('Aborted. Run `php artisan cloud:mark-inactive` first, then re-run this command.');

            return 1;
        }

        if (! $this->confirm('Send maintenance notification to all '.$totalUsers.' users now?', false)) {
            $this->warn('Aborted.');

            return 1;
        }
        $this->newLine();

        $stats = ['active_sent' => 0, 'inactive_sent' => 0, 'failed' => 0, 'skipped' => 0];
        $dispatched = 0;
        $startedAt = now();

        User::query()
            ->select(['id', 'email', 'is_inactive'])
            ->where('id', '!=', 0)
            ->whereNotNull('email')
            ->orderBy('id')
            ->chunkById(500, function ($users) use ($maintenanceAt, $dryRun, $startedAt, $throttlePerMinute, &$stats, &$dispatched) {
                foreach ($users as $user) {
                    if (! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
                        $stats['skipped']++;

                        continue;
                    }

                    $isInactive = (bool) $user->is_inactive;
                    $line = sprintf('  [%s] %s', $isInactive ? 'INACTIVE' : 'ACTIVE  ', $user->email);

                    if ($dryRun) {
                        $this->line($line.' (dry-run)');
                        $isInactive ? $stats['inactive_sent']++ : $stats['active_sent']++;

                        continue;
                    }

                    try {
                        $delayUntil = $startedAt->copy()->addSeconds(intdiv($dispatched * 60, $throttlePerMinute));
                        $user->notify((new MaintenanceNotice($user, $maintenanceAt))->delay($delayUntil));
                        $isInactive ? $stats['inactive_sent']++ : $stats['active_sent']++;
                        $dispatched++;
                        $this->line($line.' (delayed until '.$delayUntil->format('H:i:s').' UTC)');
                    } catch (\Throwable $e) {
                        $stats['failed']++;
                        $this->warn($line.' FAILED: '.$e->getMessage());
                    }
                }
            });

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            collect($stats)->map(fn ($v, $k) => [$k, $v])->values()->all(),
        );

        if (! $dryRun) {
            $finishesAt = $startedAt->copy()->addSeconds(intdiv($dispatched * 60, $throttlePerMinute));
            $this->newLine();
            $this->info('All '.$dispatched.' notifications queued. Last email scheduled to send around '.$finishesAt->format('Y-m-d H:i:s').' UTC.');
        }

        return 0;
    }

    private function sendTestEmails(string $email, Carbon $maintenanceAt): int
    {
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Invalid email address: '.$email);

            return 1;
        }

        $this->info('Sending TEST maintenance emails (active, inactive, active+inactive-team variants)');
        $this->line('  To                 : '.$email);
        $this->line('  Maintenance window : '.$maintenanceAt->copy()->setTimezone('Europe/Budapest')->format('Y-m-d H:i').' CET/CEST  ('.$maintenanceAt->format('Y-m-d H:i').' UTC)');
        $this->newLine();

        $variants = [
            ['label' => 'ACTIVE                 ', 'inactive' => false, 'hasInactiveTeams' => false],
            ['label' => 'INACTIVE               ', 'inactive' => true,  'hasInactiveTeams' => false],
            ['label' => 'ACTIVE + INACTIVE TEAM ', 'inactive' => false, 'hasInactiveTeams' => true],
        ];

        $exitCode = 0;
        foreach ($variants as $variant) {
            $user = new User;
            $user->name = 'Test Recipient';
            $user->email = $email;
            $user->is_inactive = $variant['inactive'];

            try {
                $user->notifyNow(new MaintenanceNotice($user, $maintenanceAt, hasInactiveTeamsOverride: $variant['hasInactiveTeams']));
                $this->line('  ['.$variant['label'].'] sent');
            } catch (\Throwable $e) {
                $this->warn('  ['.$variant['label'].'] FAILED: '.$e->getMessage());
                $exitCode = 1;
            }
        }

        return $exitCode;
    }
}
