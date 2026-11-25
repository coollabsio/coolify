<?php

namespace App\Services\DeployTriggers;

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Server;
use App\Services\DeployTriggers\Contract\ApplicationDecision;
use App\Services\DeployTriggers\Contract\EvaluateApplicationInput;
use App\Services\DeployTriggers\Contract\EvaluateRequest;
use Illuminate\Support\Collection;

/**
 * Turns a push and its candidate applications into one coolify-affected request.
 */
class EvaluationRequestBuilder
{
    /**
     * @param  Collection<int, Application>  $applications
     * @return array{server: ?Server, request: ?EvaluateRequest, access: ?GitAccess, candidates: array<string, TriggerCandidate>, prechecked: array<string, ApplicationDecision>}
     */
    public function build(Collection $applications, PushContext $context): array
    {
        $candidates = [];
        $prechecked = [];
        foreach ($applications as $application) {
            $candidate = $this->candidate($application, $context);
            $candidates[$candidate->id] = $candidate;
            $decision = $this->precheck($candidate, $context);
            if ($decision !== null) {
                $prechecked[$candidate->id] = $decision;
            }
        }

        $remaining = array_diff_key($candidates, $prechecked);
        if ($remaining === []) {
            return ['server' => null, 'request' => null, 'access' => null, 'candidates' => $candidates, 'prechecked' => $prechecked];
        }

        $first = reset($remaining)->application;
        $access = $first->gitAccessForAffected();
        $fetchRefs = [$context->ref];
        if ($context->pullRequest !== null && collect($remaining)->contains(fn (TriggerCandidate $c): bool => $c->preview === null)) {
            $fetchRefs[] = "refs/heads/{$context->pullRequest->targetBranch}";
        }

        $request = new EvaluateRequest(
            repositoryUrl: $access->repositoryUrl,
            mirrorKey: EvaluateRequest::mirrorKeyFor($context->teamId, $context->repositoryUrl),
            gitEnv: $access->env,
            gitConfig: $access->config,
            after: $context->after,
            fetchRefs: $fetchRefs,
            isFork: (bool) $context->pullRequest?->isFork,
            submodules: (bool) $first->settings?->is_git_submodules_enabled,
            applications: array_values(array_map(fn (TriggerCandidate $candidate): EvaluateApplicationInput => $this->input($candidate, $context), $remaining)),
            limits: EvaluateRequest::defaultLimits((int) config('constants.coolify.push_evaluation.max_mirror_bytes', EvaluateRequest::DEFAULT_MAX_MIRROR_BYTES)),
        );

        return ['server' => $this->server($applications), 'request' => $request, 'access' => $access, 'candidates' => $candidates, 'prechecked' => $prechecked];
    }

    private function candidate(Application $application, PushContext $context): TriggerCandidate
    {
        $pullRequest = $context->pullRequest;
        $preview = $pullRequest === null ? null : ApplicationPreview::query()
            ->where('application_id', $application->id)
            ->where('pull_request_id', $pullRequest->number)
            ->first();

        ['base' => $base, 'isStored' => $isStored] = $pullRequest !== null && $preview === null
            ? ['base' => null, 'isStored' => false]
            : EffectiveBase::for($application, $preview);
        $fingerprint = TriggerFingerprint::for($application, $preview);

        return new TriggerCandidate(
            id: $pullRequest === null ? $application->uuid : "{$application->uuid}:pr-{$pullRequest->number}",
            application: $application,
            preview: $preview,
            mode: filled($application->watch_paths) ? 'watch_paths' : 'smart',
            base: $base,
            isStored: $isStored,
            fingerprint: $fingerprint,
            scanValid: $this->scanIsValid($application, $preview, $pullRequest !== null, $base, $fingerprint),
        );
    }

    private function scanIsValid(Application $application, ?ApplicationPreview $preview, bool $isPullRequest, ?string $base, string $fingerprint): bool
    {
        // A new preview has no scan yet; it borrows the scan of the application while that is valid.
        if ($isPullRequest && $preview === null) {
            $preview = null;
            $base = $application->trigger_base_commit;
            $fingerprint = TriggerFingerprint::for($application);
        }

        $scan = ($preview ?? $application)->trigger_scan;

        return is_array($scan)
            && $base !== null
            && ($scan['commit'] ?? null) === $base
            && ($scan['config_fingerprint'] ?? null) === $fingerprint
            && ($scan['widened'] ?? []) === [];
    }

    private function precheck(TriggerCandidate $candidate, PushContext $context): ?ApplicationDecision
    {
        if ($context->pullRequest?->isFork) {
            return ApplicationDecision::deployBecause($candidate->id, 'fork_no_narrowing', 'Pull requests from forks always deploy.');
        }

        $isNewPreview = $context->pullRequest !== null && $candidate->preview === null;
        if ($candidate->base === null && ! $isNewPreview) {
            return ApplicationDecision::deployBecause($candidate->id, 'no_base', 'Coolify does not know the deployed commit yet, so this push deploys.');
        }

        if ($candidate->mode === 'smart' && ! $candidate->scanValid) {
            return ApplicationDecision::deployBecause($candidate->id, 'no_scan', 'The files this application references are not known yet, so this push deploys.', $candidate->base);
        }

        return null;
    }

    private function input(TriggerCandidate $candidate, PushContext $context): EvaluateApplicationInput
    {
        $application = $candidate->application;
        $scan = ($candidate->preview ?? $application)->trigger_scan;

        return new EvaluateApplicationInput(
            id: $candidate->id,
            baseCommit: $candidate->base,
            mergeBaseRef: $context->pullRequest !== null && $candidate->preview === null ? "refs/heads/{$context->pullRequest->targetBranch}" : null,
            mode: $candidate->mode,
            watchPaths: $candidate->mode === 'watch_paths' ? $application->watch_paths : null,
            buildPack: (string) $application->build_pack,
            baseDirectory: $application->base_directory ?: '/',
            dockerfileLocation: $application->build_pack === 'dockerfile' ? $application->dockerfile_location : null,
            dockerComposeLocation: $application->build_pack === 'dockercompose' ? $application->docker_compose_location : null,
            commands: [
                'install' => $application->install_command,
                'build' => $application->build_command,
                'start' => $application->start_command,
                'compose_build' => $application->docker_compose_custom_build_command,
                'compose_start' => $application->docker_compose_custom_start_command,
            ],
            referencePaths: array_values(array_filter($scan['reference_paths'] ?? [], 'is_string')),
            managedConfigPaths: $application->managedConfigPaths(),
        );
    }

    /**
     * The lowest-id functional server among the build servers of the candidates, else their destination servers.
     *
     * @param  Collection<int, Application>  $applications
     */
    private function server(Collection $applications): ?Server
    {
        $teamId = $applications->first()?->environment?->project?->team_id;
        $usesBuildServer = $applications->contains(fn (Application $application): bool => (bool) $application->settings?->is_build_server_enabled);
        $buildServers = $usesBuildServer && $teamId !== null
            ? Server::query()->where('team_id', $teamId)->whereRelation('settings', 'is_build_server', true)->orderBy('id')->get()
            : collect();

        $pick = fn (Collection $servers): ?Server => $servers->sortBy('id')->first(fn (Server $server): bool => $server->isFunctional());

        return $pick($buildServers) ?? $pick($applications->map(fn (Application $application) => $application->destination?->server)->filter()->unique('id'));
    }
}
