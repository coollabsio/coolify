<?php

namespace App\Console\Commands;

use App\Services\HorizonNodeDrainer;
use Illuminate\Console\Command;

class DrainHorizonNode extends Command
{
    protected $signature = 'horizon:drain
        {--dry-run : List the running work of this node and change nothing}
        {--timeout=120 : Seconds to wait until each stopped cron job has ended}';

    protected $description = 'Pause Horizon on this Cloud node and move its running deployments, scheduled tasks, and backups to the other node';

    public function handle(HorizonNodeDrainer $drainer): int
    {
        $error = $drainer->preflightError();
        if ($error !== null) {
            $this->error($error);

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $items = $drainer->runningWork();
            if ($items === []) {
                $this->info('No running work on this node.');

                return self::SUCCESS;
            }
            $this->table(['Kind', 'Resource', 'Running'], array_map(fn (array $item) => [$item['kind'], $item['resource'], $item['old_id']], $items));

            return self::SUCCESS;
        }

        if (! $drainer->pauseThisNode()) {
            $this->error('The Horizon masters of this node did not report paused. Nothing was moved.');

            return self::FAILURE;
        }
        $this->info('Horizon on this node is paused.');

        $items = $drainer->drain(max(1, (int) $this->option('timeout')));
        if ($items === []) {
            $this->info('No running work on this node.');
        } else {
            $this->table(['Kind', 'Resource', 'Old', 'New', 'Problem'], array_map(fn (array $item) => [$item['kind'], $item['resource'], $item['old_id'], $item['new_id'] ?? '-', $item['failure'] ?? ''], $items));
        }
        $this->info('This node stays paused until you run `php artisan horizon:continue` or restart Horizon.');

        $notMoved = array_filter($items, fn (array $item) => $item['new_id'] === null);

        return $notMoved === [] ? self::SUCCESS : self::FAILURE;
    }
}
