<?php

namespace App\Services\ProjectConfig;

use App\Enums\ConfigRevisionStatus;
use App\Models\EnvironmentConfigLink;
use App\Models\EnvironmentConfigRevision;
use App\Models\User;
use App\Notifications\ProjectConfig\ConfigApplyFailed;
use App\Notifications\ProjectConfig\ConfigApprovalNeeded;

/**
 * Plans a file for a link and stores the result as a revision.
 */
class ConfigRevisions
{
    public function __construct(
        private readonly ConfigParser $parser,
        private readonly ConfigResolver $resolver,
        private readonly ConfigPlanner $planner,
    ) {}

    /**
     * @param  list<array{application_uuid: string, commit_sha: ?string}>  $heldDeployments
     */
    public function record(
        EnvironmentConfigLink $link,
        string $raw,
        string $trigger,
        ?string $commit = null,
        ?User $creator = null,
        array $heldDeployments = [],
    ): EnvironmentConfigRevision {
        $attributes = [
            'link_id' => $link->id,
            'trigger' => $trigger,
            'commit_sha' => $commit,
            'file_hash' => hash('sha256', $raw),
            'file_contents' => $raw,
            'created_by' => $creator?->id,
            'held_deployments' => $heldDeployments === [] ? null : $heldDeployments,
        ];

        try {
            $resolved = $this->resolve($link, $raw);
        } catch (ConfigException $e) {
            return EnvironmentConfigRevision::create($attributes + [
                'status' => ConfigRevisionStatus::Failed,
                'error' => $e->getMessage(),
                'plan' => ['errors' => array_map(fn (ConfigError $error) => $error->toArray(), $e->errors())],
            ]);
        }

        $plan = $this->planner->plan($resolved, ApplyContext::for($link, $creator));

        $revision = EnvironmentConfigRevision::create($attributes + [
            'format_version' => $resolved->formatVersion,
            'state_hash' => $plan->stateHash,
            'plan' => $plan->toArray(),
            'status' => $this->statusFor($link, $plan),
            'error' => $plan->hasErrors() ? collect($plan->errors)->map(fn (ConfigError $e) => "{$e->path}: {$e->message}")->implode(' ') : null,
        ]);
        if ($revision->status === ConfigRevisionStatus::WaitingApproval && $trigger !== 'api') {
            $link->environment->project->team?->notify(new ConfigApprovalNeeded($revision));
        }

        return $revision;
    }

    public function notifyFailed(EnvironmentConfigRevision $revision): void
    {
        if ($revision->trigger !== 'api') {
            $revision->link->environment->project->team?->notify(new ConfigApplyFailed($revision));
        }
    }

    public function resolve(EnvironmentConfigLink $link, string $raw): ResolvedEnvironment
    {
        $environment = $link->environment;
        $document = $this->parser->parse($raw, $environment->project->name);

        return $link->isGit()
            ? $this->resolver->forBranch($document, (string) $link->branch)
            : $this->resolver->forEnvironment($document, $environment->name);
    }

    /**
     * Older plans of the link are replaced. Their held deployments move to this revision.
     */
    public function supersedeOlder(EnvironmentConfigRevision $revision): void
    {
        $older = EnvironmentConfigRevision::query()
            ->where('link_id', $revision->link_id)
            ->where('id', '<', $revision->id)
            ->whereIn('status', [ConfigRevisionStatus::Planned->value, ConfigRevisionStatus::WaitingApproval->value])
            ->get();
        if ($older->isEmpty()) {
            return;
        }

        $held = collect($revision->held_deployments ?? []);
        foreach ($older as $old) {
            foreach ($old->held_deployments ?? [] as $entry) {
                if (! $held->contains('application_uuid', $entry['application_uuid'])) {
                    $held->push($entry);
                }
            }
            $old->update(['status' => ConfigRevisionStatus::Superseded]);
        }
        $revision->update(['held_deployments' => $held->values()->all()]);
    }

    private function statusFor(EnvironmentConfigLink $link, ConfigPlan $plan): ConfigRevisionStatus
    {
        if ($plan->hasErrors()) {
            return ConfigRevisionStatus::Failed;
        }
        if ($plan->isDestructive() || (! $link->auto_apply && ! $plan->isEmpty())) {
            return ConfigRevisionStatus::WaitingApproval;
        }

        return ConfigRevisionStatus::Planned;
    }
}
