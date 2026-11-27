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
 * Turns a push and its candidate applications into one evaluation request.
 */
class EvaluationRequestBuilder
{
    /**
     * @param  Collection<int, Application>  $applications
     * @return array{server: ?Server, request: ?EvaluateRequest, access: ?GitAccess, candidates: array<string, TriggerCandidate>, prechecked: array<string, ApplicationDecision>}
     */
    public function build(Collection $applications, PushContext $context, bool $dryRun = false): array
    {
        $candidates = [];
        $prechecked = [];
        foreach ($applications as $application) {
            $candidate = $this->candidate($application, $context);
            if ($dryRun) {
                // A dry run only resolves the trigger set at the evaluated commit.
                $candidates[$candidate->id] = new TriggerCandidate($candidate->id, $candidate->application, $candidate->preview, $candidate->mode,
                    $candidate->base ?? $context->after, false, $candidate->fingerprint);

                continue;
            }
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

        ['server' => $server, 'application' => $owner] = $this->server(collect($remaining)->map(fn (TriggerCandidate $c): Application => $c->application)->unique('id')->values());
        $access = ($owner ?? reset($remaining)->application)->gitAccessForAffected();
        $fetchRefs = [$context->ref];
        if ($context->pullRequest !== null && collect($remaining)->contains(fn (TriggerCandidate $c): bool => $c->preview === null)) {
            $fetchRefs[] = "refs/heads/{$context->pullRequest->targetBranch}";
        }

        $request = new EvaluateRequest(
            repositoryUrl: $access->repositoryUrl,
            mirrorKey: EvaluateRequest::mirrorKeyFor($context->teamId, strtolower(preg_replace('/\.git\z/', '', rtrim($access->repositoryUrl, '/')))),
            after: $context->after,
            fetchRefs: $fetchRefs,
            applications: array_values(array_map(fn (TriggerCandidate $candidate): EvaluateApplicationInput => $this->input($candidate, $context, $dryRun), $remaining)),
            limits: EvaluateRequest::defaultLimits((int) config('constants.coolify.push_evaluation.max_mirror_bytes', EvaluateRequest::DEFAULT_MAX_MIRROR_BYTES)),
        );

        return ['server' => $server, 'request' => $request, 'access' => $access, 'candidates' => $candidates, 'prechecked' => $prechecked];
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
        );
    }

    private function precheck(TriggerCandidate $candidate, PushContext $context): ?ApplicationDecision
    {
        if ($context->pullRequest?->isFork) {
            return ApplicationDecision::deployBecause($candidate->id, 'fork_no_narrowing', 'Pull requests from forks always deploy.');
        }

        // The application may have changed while the push waited for its evaluation.
        if (PushTriggerRouter::forPush($context)->route($candidate->application) !== TriggerRoute::Evaluate) {
            return ApplicationDecision::deployBecause($candidate->id, 'triggers_off', 'Deploy triggers no longer decide the pushes of this application, so this push deploys.');
        }
        if ($context->pullRequest === null && $candidate->application->hasPendingDeploymentConfigurationChanges()) {
            return ApplicationDecision::deployBecause($candidate->id, 'config_changed', 'The configuration of the application changed since its last deployment, so this push deploys.');
        }

        $isNewPreview = $context->pullRequest !== null && $candidate->preview === null;
        if ($candidate->base === null && $candidate->mode === 'watch_paths' && $context->payloadChangedFiles !== null) {
            return LegacyWatchPaths::decide($candidate, $context->payloadChangedFiles);
        }
        if ($candidate->base === null && ! $isNewPreview) {
            return ApplicationDecision::deployBecause($candidate->id, 'no_base', 'Coolify does not know the deployed commit yet, so this push deploys.');
        }

        return null;
    }

    private function input(TriggerCandidate $candidate, PushContext $context, bool $dryRun): EvaluateApplicationInput
    {
        $application = $candidate->application;

        return new EvaluateApplicationInput(
            id: $candidate->id,
            baseCommit: $candidate->base,
            mergeBaseRef: $context->pullRequest !== null && $candidate->preview === null ? "refs/heads/{$context->pullRequest->targetBranch}" : null,
            mode: $candidate->mode,
            watchPaths: $candidate->mode === 'watch_paths' ? $application->effectiveWatchPaths() : null,
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
            managedConfigPaths: $application->managedConfigPaths(),
            storedTriggerSet: $dryRun || $candidate->mode !== 'smart' ? null : $candidate->validStoredSet(),
        );
    }

    /**
     * A functional server that already clones the repository for one of the applications, and that application.
     * Its git credential then never reaches a server that the application does not deploy with.
     *
     * @param  Collection<int, Application>  $applications
     * @return array{server: ?Server, application: ?Application}
     */
    private function server(Collection $applications): array
    {
        foreach ($applications as $application) {
            $server = $this->cloningServers($application)->sortBy('id')->first(fn (Server $server): bool => $server->isFunctional());
            if ($server !== null) {
                return ['server' => $server, 'application' => $application];
            }
        }

        return ['server' => null, 'application' => null];
    }

    /**
     * The build servers that build the application, else its destination server.
     *
     * @return Collection<int, Server>
     */
    private function cloningServers(Application $application): Collection
    {
        $server = $application->destination?->server;
        if ($server === null) {
            return collect();
        }

        $buildsElsewhere = (bool) $application->settings?->is_build_server_enabled || $application->mustBuildOnBuildServer($server);

        return $buildsElsewhere ? $application->buildServerCandidates($server) : collect([$server]);
    }
}
