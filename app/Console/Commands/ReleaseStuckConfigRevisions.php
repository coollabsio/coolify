<?php

namespace App\Console\Commands;

use App\Enums\ConfigRevisionStatus;
use App\Models\EnvironmentConfigRevision;
use App\Services\ProjectConfig\ConfigPushRouter;
use Illuminate\Console\Command;

class ReleaseStuckConfigRevisions extends Command
{
    public const TIMEOUT_MINUTES = 15;

    protected $signature = 'project-config:release-stuck';

    protected $description = 'Mark coolify.json applies that did not finish as failed';

    public function handle(): int
    {
        $count = EnvironmentConfigRevision::query()
            ->where('status', ConfigRevisionStatus::Applying->value)
            ->where('started_at', '<', now()->subMinutes(self::TIMEOUT_MINUTES))
            ->update(['status' => ConfigRevisionStatus::Failed->value, 'error' => 'Apply did not finish.']);
        $this->info("Released {$count} revision(s).");
        $recovered = ConfigPushRouter::recoverLostPushes();
        $this->info("Planned {$recovered} push(es) whose webhook did not finish.");

        return self::SUCCESS;
    }
}
