<?php

namespace App\Services\DeployTriggers;

use App\Models\GithubApp;
use App\Services\DeployTriggers\Contract\ApplicationDecision;
use InvalidArgumentException;

/**
 * Decides GitHub App pushes in PHP when the stored trigger set is provably still valid (spec §3.3).
 */
class GithubFastPath
{
    /**
     * @param  array<string, TriggerCandidate>  $candidates
     * @return array<string, ApplicationDecision> decisions for the candidates it could decide
     */
    public function decide(array $candidates, PushContext $context): array
    {
        if ($context->provider !== 'github' || $context->githubAppId === null || $context->forced !== false
            || $context->after === null || $context->repositoryFullName === null || ! str_contains($context->repositoryFullName, '/')) {
            return [];
        }

        $source = GithubApp::query()->find($context->githubAppId);
        if (! $source) {
            return [];
        }

        [$owner, $repo] = explode('/', $context->repositoryFullName, 2);
        $compares = [];
        $decisions = [];
        foreach ($candidates as $id => $candidate) {
            if ($candidate->base === null || ($candidate->mode === 'smart' && $candidate->validStoredSet() === null)) {
                continue;
            }

            $compare = $compares[$candidate->base] ??= getGithubCompare($source, $owner, $repo, $candidate->base, $context->after) ?? false;
            if ($compare === false || ! in_array($compare['status'], ['ahead', 'identical'], true)) {
                continue;
            }

            $decision = $this->decideOne($candidate, $compare['files']);
            if ($decision !== null) {
                $decisions[$id] = $decision;
            }
        }

        return $decisions;
    }

    /**
     * @param  list<string>  $files
     */
    private function decideOne(TriggerCandidate $candidate, array $files): ?ApplicationDecision
    {
        $id = $candidate->id;
        if ($files === []) {
            return ApplicationDecision::deployBecause($id, 'empty_diff', 'The push changes no file, so it deploys.', $candidate->base);
        }

        $count = count($files);
        if ($candidate->mode === 'watch_paths') {
            $matched = LegacyWatchPaths::matches($candidate->application, $files);

            return $matched->isEmpty()
                ? new ApplicationDecision($id, 'skip', 'watch_paths_no_match', "None of the {$count} changed files match the watch paths.", $candidate->base)
                : new ApplicationDecision($id, 'deploy', 'watch_paths_matched', "{$matched->first()} matches the watch paths.", $candidate->base, [['file' => $matched->first(), 'rule' => 'watch_paths']]);
        }

        $set = $candidate->validStoredSet();
        try {
            $inputs = CompiledTriggerSet::fromArray($set['input_patterns']);
            $compiled = CompiledTriggerSet::fromArray($set['compiled']);
            foreach ($files as $file) {
                if ($inputs->matches($file) || $compiled->isLockfile($file)) {
                    return null;
                }
            }

            $decision = TriggerSetDecider::decide($id, $set, $files, $candidate->base)['decision'];

            // A change in the application's own files can add a reference; the next full evaluation checks again.
            return $decision?->deploys()
                ? new ApplicationDecision($id, 'deploy', $decision->reasonCode, $decision->reason, $decision->base, $decision->matches, triggerSet: [...$set, 'references_checked' => false])
                : $decision;
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
