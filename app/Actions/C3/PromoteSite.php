<?php

namespace App\Actions\C3;

use App\Actions\Shared\CheckDomainDns;
use App\Exceptions\C3PromotionBlockedException;
use App\Models\Project;
use App\Notifications\Internal\GeneralNotification;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Connect3 fork: staged -> live (PRD section 4.3).
 *
 * Pre-flight: every domain must resolve to this server (or to Cloudflare) and must not be in
 * use by another resource. On failure a C3PromotionBlockedException is thrown and nothing is
 * changed. On success only the project row and the proxy's file-provider config change.
 *
 * @return array<string, array{status: string, message: string}> DNS results per domain
 */
class PromoteSite
{
    use AsAction;

    public function handle(Project $project, array|string $domains): array
    {
        if (! c3_enabled()) {
            throw new C3PromotionBlockedException('Staging apex is not configured in instance settings.');
        }
        if (blank($project->client_slug)) {
            throw new C3PromotionBlockedException('Project has no client slug.');
        }
        $hosts = c3_parseDomainList($domains);
        if ($hosts === []) {
            throw new C3PromotionBlockedException('Enter at least one valid client domain.');
        }
        $apex = c3_stagingApex();
        foreach ($hosts as $host) {
            if ($host === $apex || c3_isStagingHost($host, $apex)) {
                throw new C3PromotionBlockedException("{$host} is under the staging apex and cannot be a live domain.");
            }
        }

        $application = $project->primaryApplication();
        if (! $application) {
            throw new C3PromotionBlockedException('Project has no application with a domain to promote.');
        }
        $server = data_get($application, 'destination.server');
        if (! $server) {
            throw new C3PromotionBlockedException('Primary application has no server.');
        }

        $conflicts = [];
        foreach ($hosts as $host) {
            try {
                $usage = checkDomainUsage(resource: $application, domain: "https://{$host}");
                if (data_get($usage, 'hasConflicts')) {
                    $conflicts[] = $host;
                }
            } catch (\Throwable) {
                // conflict detection is best-effort
            }
        }
        if ($conflicts !== []) {
            throw new C3PromotionBlockedException('Already used by another resource: '.implode(', ', $conflicts).'.');
        }

        $expectedIp = serverDnsTargetIp($server) ?? $server->ip;
        $entries = collect($hosts)->mapWithKeys(fn ($host) => [$host => "https://{$host}"])->all();
        $results = CheckDomainDns::run($entries, $server, $expectedIp, false, 10);

        $failed = collect($results)->filter(fn ($r) => data_get($r, 'status') !== 'ok');
        if ($failed->isNotEmpty()) {
            $lines = $failed->map(fn ($r, $host) => "{$host}: ".data_get($r, 'message'))->values()->join(' ');
            throw new C3PromotionBlockedException(
                "DNS pre-flight failed, nothing was changed. Point the domain(s) at {$expectedIp} (or proxy them through Cloudflare) and retry. {$lines}",
                $results,
            );
        }

        $project->live_domains = $hosts;
        $project->site_state = C3_STATE_LIVE;
        $project->save();

        auditLog('c3.site.promoted', [
            'project_uuid' => $project->uuid,
            'client_slug' => $project->client_slug,
            'domains' => $hosts,
        ]);
        $project->team?->notify(new GeneralNotification(
            "Connect3: **{$project->name}** ({$project->client_slug}) promoted to live on ".implode(', ', $hosts).'. Staging URL '.$project->stagingUrl().' stays private.'
        ));

        return $results;
    }
}
