<?php

namespace App\Services\DeployTriggers;

use App\Models\Application;
use App\Services\DeployTriggers\Contract\ApplicationDecision;
use Illuminate\Support\Collection;

/**
 * Today's watch-paths check on the file list of the webhook, used when no exact diff is available.
 */
final class LegacyWatchPaths
{
    public static function decide(TriggerCandidate $candidate, PushContext $context): ApplicationDecision
    {
        $changedFiles = $context->payloadChangedFiles;
        if ($changedFiles === null) {
            return ApplicationDecision::deployBecause($candidate->id, 'no_file_list', 'The webhook has no list of changed files, so it deploys.');
        }
        if ($context->followsUnevaluatedPush($candidate->application)) {
            return ApplicationDecision::deployBecause($candidate->id, 'earlier_push_unevaluated', 'An earlier push of the branch was not evaluated, and the file list of the webhook covers only this push, so it deploys.');
        }

        $matched = self::matches($candidate->application, $changedFiles);

        return $matched->isEmpty()
            ? new ApplicationDecision($candidate->id, 'skip', 'watch_paths_no_match', 'None of the changed files match the watch paths.', $candidate->base)
            : new ApplicationDecision($candidate->id, 'deploy', 'watch_paths_matched', "{$matched->first()} matches the watch paths.", $candidate->base, [['file' => $matched->first(), 'rule' => 'watch_paths']]);
    }

    /**
     * The changed files that the watch paths match, with the semantics of Application::isWatchPathsTriggered().
     *
     * @param  list<string>  $changedFiles
     * @return Collection<int, string>
     */
    public static function matches(Application $application, array $changedFiles): Collection
    {
        // Blank watch paths deploy every push, as the webhooks always did.
        if (blank($application->watch_paths)) {
            return collect($changedFiles);
        }

        return Application::matchPaths(collect($changedFiles), collect(explode("\n", (string) $application->effectiveWatchPaths())));
    }
}
