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
            if ($candidate->base === null || ! $this->storedSetIsValid($candidate)) {
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

    private function storedSetIsValid(TriggerCandidate $candidate): bool
    {
        if ($candidate->mode === 'watch_paths') {
            return true;
        }

        $set = $candidate->storedSet();

        return $candidate->scanValid
            && is_array($set)
            && ($set['evaluated_commit'] ?? null) === $candidate->base
            && ($set['config_fingerprint'] ?? null) === $candidate->fingerprint
            && ($set['last_error'] ?? null) === null
            && is_array($set['compiled'] ?? null)
            && is_array($set['input_patterns'] ?? null);
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

        $set = $candidate->storedSet();
        try {
            $compiled = CompiledTriggerSet::fromArray($set['compiled']);
            $inputs = CompiledTriggerSet::fromArray($set['input_patterns']);
        } catch (InvalidArgumentException) {
            return null;
        }

        foreach ($files as $file) {
            if ($inputs->matches($file) || $compiled->isLockfile($file)) {
                return null;
            }
        }

        $kept = array_diff_key($set, array_flip(['evaluated_commit', 'config_fingerprint', 'evaluated_at', 'last_error']));
        $match = $compiled->firstMatch($files);

        return $match === null
            ? new ApplicationDecision($id, 'skip', 'no_match', "None of the {$count} changed files affect this application.", $candidate->base, triggerSet: $kept)
            : new ApplicationDecision($id, 'deploy', 'matched', "{$match} changed.", $candidate->base, [['file' => $match, 'rule' => 'trigger_set']], triggerSet: $kept);
    }
}
