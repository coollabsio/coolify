<?php

namespace App\Actions\C3;

use App\Models\Project;
use App\Notifications\Internal\GeneralNotification;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Connect3 fork: live -> staged. Removes the live routers from the proxy, keeps the domains
 * on the project so a later re-promotion is one click, leaves certificates cached in Traefik.
 */
class DemoteSite
{
    use AsAction;

    public function handle(Project $project, ?string $reason = null): void
    {
        if (! $project->isLive()) {
            return;
        }
        $project->site_state = C3_STATE_STAGED;
        $project->save();

        auditLog('c3.site.demoted', [
            'project_uuid' => $project->uuid,
            'client_slug' => $project->client_slug,
            'reason' => $reason,
        ]);
        $project->team?->notify(new GeneralNotification(
            "Connect3: **{$project->name}** ({$project->client_slug}) demoted to staged.".($reason ? " Reason: {$reason}" : ''),
            success: false,
        ));
    }
}
