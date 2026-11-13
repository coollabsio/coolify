<?php

namespace App\Console\Commands;

use App\Enums\ConfigRevisionStatus;
use App\Jobs\ApplyEnvironmentConfigJob;
use App\Models\EnvironmentConfigRevision;
use App\Services\ProjectConfig\ConfigPushRouter;
use App\Services\ProjectConfig\ConfigRevisions;
use Illuminate\Console\Command;

class ReleaseStuckConfigRevisions extends Command
{
    public const TIMEOUT_MINUTES = 15;

    protected $signature = 'project-config:release-stuck';

    protected $description = 'Mark coolify.json applies that did not finish as failed, and apply planned revisions whose job was lost';

    public function handle(ConfigRevisions $revisions): int
    {
        $stuck = EnvironmentConfigRevision::query()
            ->where('status', ConfigRevisionStatus::Applying->value)
            ->where('started_at', '<', now()->subMinutes(self::TIMEOUT_MINUTES))
            ->get();
        $count = $stuck->filter(fn (EnvironmentConfigRevision $revision) => $revisions->fail($revision, 'Apply did not finish.', [ConfigRevisionStatus::Applying]))->count();
        $this->info("Released {$count} revision(s).");

        // The apply job of a planned revision was lost. The applier claims each revision one time, so a second job is safe.
        $planned = EnvironmentConfigRevision::query()
            ->where('status', ConfigRevisionStatus::Planned->value)
            ->where('updated_at', '<', now()->subMinutes(self::TIMEOUT_MINUTES))
            ->whereHas('link', fn ($query) => $query->whereNull('suspended_at'))
            ->get();
        $planned->each(fn (EnvironmentConfigRevision $revision) => ApplyEnvironmentConfigJob::dispatch($revision));
        $this->info("Applied {$planned->count()} planned revision(s) again.");

        $recovered = ConfigPushRouter::recoverLostPushes();
        $this->info("Planned {$recovered} push(es) whose webhook did not finish.");

        return self::SUCCESS;
    }
}
