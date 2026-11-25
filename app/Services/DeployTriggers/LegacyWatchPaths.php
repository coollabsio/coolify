<?php

namespace App\Services\DeployTriggers;

use App\Models\Application;
use App\Services\DeployTriggers\Contract\ApplicationDecision;

/**
 * Today's watch-paths check on the file list of the webhook, used when no exact diff is available.
 */
final class LegacyWatchPaths
{
    /**
     * @param  list<string>|null  $changedFiles  null when the webhook has no file list
     */
    public static function decide(TriggerCandidate $candidate, ?array $changedFiles): ApplicationDecision
    {
        if ($changedFiles === null) {
            return ApplicationDecision::deployBecause($candidate->id, 'no_file_list', 'The webhook has no list of changed files, so it deploys.');
        }

        $matched = Application::matchPaths(collect($changedFiles), collect(explode("\n", (string) $candidate->application->watch_paths)));

        return $matched->isEmpty()
            ? new ApplicationDecision($candidate->id, 'skip', 'watch_paths_no_match', 'None of the changed files match the watch paths.', $candidate->base)
            : new ApplicationDecision($candidate->id, 'deploy', 'watch_paths_matched', "{$matched->first()} matches the watch paths.", $candidate->base, [['file' => $matched->first(), 'rule' => 'watch_paths']]);
    }
}
