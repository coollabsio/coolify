<?php

namespace App\Services\ImageScan;

use App\Actions\ImageScan\RefreshCandidates;
use App\Actions\ImageScan\UpdateResourceImage;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\ImageScanStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ImageScan;
use App\Models\ImageScanNotificationState;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\Team;
use App\Notifications\ImageScan\KnownExploitedFound;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * What the image vulnerability notifications told a team, kept in one `image_scan_notification_states` row per scan
 * owner (application, service application or database, standalone database) for spec 8.
 *
 * - The current scans of an owner are `ResourceScans::currentFor()`: completed, never previews.
 * - Without a state row the owner is a baseline: the row is created from the current fixable-now IDs at the alert
 *   level and the current better candidates, and nothing is sent. The baseline also records, without notifying, the
 *   other images of the same deployment (Compose) and the candidate checks that follow. It ends with the next scan
 *   that is not part of that deployment, or with the next digest.
 * - A finding is new when none of its ID and aliases is in `notified_ids`; a candidate is new when its key is not in
 *   `notified_candidates`. The known exploited alert and the digest add what they reported.
 * - The digest removes IDs and candidates that no current scan has any more, so a regression notifies again. It
 *   skips that while a deployment of the application runs (its current scans can be incomplete), and it keeps the
 *   candidates while a candidate check failed.
 * - Every write locks the state row, so the alert and the digest never overwrite each other.
 * - Preview scans never notify and never change the state.
 */
final class ScanNotifications
{
    public const SEVERITIES = ['critical', 'high', 'medium', 'low', 'unknown'];

    /** Candidates kept per resource; the oldest go first. */
    public const MAX_NOTIFIED_CANDIDATES = 200;

    /**
     * After a scan of the owner completed, outside the scan lock: creates or extends the baseline, or alerts the
     * team right away for a new known exploited finding that is fixable now.
     *
     * @param  ?int  $deploymentId  The deployment that ran this scan; null for rescans and scans after a start.
     * @param  bool  $candidateCheck  Called after a candidate check of the scan instead of the scan itself.
     */
    public static function afterScan(ImageScan $scan, ?int $deploymentId = null, bool $candidateCheck = false): void
    {
        if (! $scan->exists || $scan->status !== ImageScanStatus::Completed) {
            return;
        }

        $owner = $scan->resource;
        $teamId = $owner instanceof Model ? self::teamId($owner) : null;
        // A rescan or Scan now belongs to the deployment its row is linked to, so the first rescan of a Compose
        // application records all its images as one baseline.
        $deploymentId ??= $scan->deployment_queue_id !== null ? (int) $scan->deployment_queue_id : null;
        if ($teamId === null || self::isPreview($deploymentId)) {
            return;
        }

        // The current scans are read under the lock, so a scan that lands meanwhile is not recorded without notice.
        [$known, $current] = self::locked($owner, function (?ImageScanNotificationState $state) use ($owner, $teamId, $scan, $deploymentId, $candidateCheck): array {
            $current = self::current($owner);
            if ($current['scans']->isEmpty()) {
                return [[], $current];
            }

            if (! $state instanceof ImageScanNotificationState) {
                self::createState($owner, $teamId, $current, $deploymentId);

                return [[], $current];
            }

            if ($state->is_baseline && $candidateCheck) {
                // A candidate check adds only what the checked scan can fix now; a redeploy meanwhile is not part of the baseline.
                $state->update([
                    'notified_ids' => self::merge((array) $state->notified_ids, self::ids(self::fixableNow($scan, $owner))),
                    'notified_candidates' => self::capCandidates([...(array) $state->notified_candidates, ...array_keys(self::betterCandidates($scan, $owner))]),
                ]);

                return [[], $current];
            }

            if ($state->is_baseline && $deploymentId !== null && $deploymentId === $state->baseline_deployment_queue_id) {
                $state->update([
                    'notified_ids' => self::merge((array) $state->notified_ids, self::ids($current['fixable'])),
                    'notified_candidates' => self::capCandidates([...(array) $state->notified_candidates, ...array_keys($current['candidates'])]),
                ]);

                return [[], $current];
            }

            $notified = self::idSet((array) $state->notified_ids);
            $known = array_values(array_filter($current['fixable'], fn (Finding $finding): bool => $finding->kev && ! self::isIn($finding, $notified)));
            $state->update([
                'is_baseline' => false,
                'notified_ids' => self::merge((array) $state->notified_ids, self::ids($known)),
            ]);

            return [$known, $current];
        });

        $team = $known === [] ? null : Team::query()->find($teamId);
        if ($team instanceof Team) {
            $source = $current['scanOf'][$known[0]->id] ?? $scan;
            $team->notify(new KnownExploitedFound($source, count($known), self::ids($known)));
        }
    }

    /**
     * What a digest reports for one owner, and what it records after sending (see `applyDigest()`). Nothing is
     * reported for a baseline or an owner without a state row; the digest records what it sees.
     *
     * @return array{new: list<Finding>, fix: ?string, newCandidates: list<string>, mark: array<string, mixed>}|null
     */
    public static function digestPlan(Model $owner): ?array
    {
        $teamId = self::teamId($owner);
        $current = self::current($owner);
        if ($teamId === null || $current['scans']->isEmpty()) {
            return null;
        }

        $state = self::stateQuery($owner)->first();
        $baseline = ! $state instanceof ImageScanNotificationState || $state->is_baseline;
        $notified = self::idSet((array) $state?->notified_ids);
        $named = array_flip((array) $state?->notified_candidates);
        $new = $baseline ? [] : array_values(array_filter($current['fixable'], fn (Finding $finding): bool => ! self::isIn($finding, $notified)));
        $newCandidates = $baseline ? [] : array_diff_key($current['candidates'], $named);

        return [
            'new' => $new,
            'fix' => array_values($current['candidates'])[0] ?? null,
            'newCandidates' => array_values($newCandidates),
            'mark' => [
                'baseline' => $baseline,
                'owner_type' => $owner->getMorphClass(),
                'owner_id' => $owner->getKey(),
                'team_id' => $teamId,
                'read_ids' => array_values((array) $state?->notified_ids),
                'read_candidates' => array_values((array) $state?->notified_candidates),
                'add_ids' => self::ids($baseline ? $current['fixable'] : $new),
                'add_candidates' => array_keys($baseline ? $current['candidates'] : $newCandidates),
                'present' => array_keys($current['present']),
                'offered' => array_keys($current['candidates']),
                'prune_ids' => ! self::isDeploying($owner),
                'prune_candidates' => ! self::isDeploying($owner) && $current['candidatesChecked'],
            ],
        ];
    }

    /**
     * Records what a digest covered under the row lock, and ends a baseline. IDs and candidates that the state had
     * when the digest read it and that no current scan has any more are removed; ones added meanwhile stay.
     *
     * @param  array<string, mixed>  $mark  From `digestPlan()`.
     */
    public static function applyDigest(array $mark): void
    {
        $query = fn () => ImageScanNotificationState::query()
            ->where('resource_type', $mark['owner_type'])
            ->where('resource_id', $mark['owner_id']);

        ImageScanNotificationState::query()->getConnection()->transaction(function () use ($mark, $query): void {
            $state = $query()->lockForUpdate()->first();
            if (! $state instanceof ImageScanNotificationState) {
                ImageScanNotificationState::query()->insertOrIgnore([
                    'resource_type' => $mark['owner_type'],
                    'resource_id' => $mark['owner_id'],
                    'team_id' => $mark['team_id'],
                    'notified_ids' => json_encode($mark['add_ids']),
                    'notified_candidates' => json_encode(self::capCandidates($mark['add_candidates'])),
                    'is_baseline' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return;
            }

            // The plan recorded a baseline without reporting it; when the baseline ended meanwhile (an alert or a
            // scan of another deployment), those findings are not added, so they can still be reported.
            if (($mark['baseline'] ?? false) && ! $state->is_baseline) {
                $mark['add_ids'] = [];
                $mark['add_candidates'] = [];
            }

            $present = array_flip($mark['present']);
            $readIds = array_flip(array_map(fn ($id): string => mb_strtolower((string) $id), $mark['read_ids']));
            $ids = array_filter(
                self::merge((array) $state->notified_ids, $mark['add_ids']),
                fn (string $id): bool => ! $mark['prune_ids'] || ! isset($readIds[mb_strtolower($id)]) || isset($present[mb_strtolower($id)]),
            );

            $offered = array_flip($mark['offered']);
            $readCandidates = array_flip($mark['read_candidates']);
            $candidates = array_filter(
                [...(array) $state->notified_candidates, ...$mark['add_candidates']],
                fn (string $key): bool => ! $mark['prune_candidates'] || ! isset($readCandidates[$key]) || isset($offered[$key]),
            );

            $state->update([
                'is_baseline' => false,
                'notified_ids' => array_values($ids),
                'notified_candidates' => self::capCandidates($candidates),
            ]);
        });
    }

    /**
     * The relevant findings that an action fixes (spec 15.5): what the stored status counts as fixable.
     *
     * @return list<Finding>
     */
    public static function fixableNow(ImageScan $scan, Model $owner, ?IgnoreMatcher $ignores = null): array
    {
        $scan->setRelation('resource', $owner);
        $byFinding = SecurityActions::forScan($scan, $ignores ?? IgnoreMatcher::forResource($owner))['byFinding'];

        return array_values(array_filter(
            ResourceScans::findings($scan),
            fn (Finding $finding): bool => isset($byFinding[$finding->id]),
        ));
    }

    /**
     * The better images the resource can switch to, as `ref@digest`, with what the fix panel says about each.
     *
     * @return array<string, string>
     */
    public static function betterCandidates(ImageScan $scan, Model $owner): array
    {
        if ((int) $owner->getKey() === 0) {
            return [];
        }

        $candidates = [];
        if (UpdateResourceImage::blockedReason($scan, $owner) === null) {
            foreach ($scan->updateCandidates() as $entry) {
                $text = self::updateText((string) $scan->image_ref, $entry);
                if ($text !== null) {
                    $candidates[$entry['ref'].'@'.($entry['digest'] ?? '')] = $text;
                }
            }
        }

        $base = FixPanel::baseImage($scan);
        if ($base !== null) {
            $candidates['base:'.$base['to']] = "Change FROM {$base['from']} to {$base['to']}.";
        }

        return $candidates;
    }

    /**
     * A link built from the instance URL, because queued notifications have no request and route() would use APP_URL.
     */
    public static function link(Model $resource): ?string
    {
        $url = TeamVulnerabilities::vulnerabilitiesUrl(self::pageResource($resource));

        return $url === null ? null : rtrim(base_url(), '/').parse_url($url, PHP_URL_PATH);
    }

    /**
     * The resource with a page: the service of a service application or database.
     */
    public static function pageResource(Model $owner): Model
    {
        return ($owner instanceof ServiceApplication || $owner instanceof ServiceDatabase) && $owner->service instanceof Model
            ? $owner->service
            : $owner;
    }

    /**
     * @param  list<Finding>  $findings
     * @return array<string, int> Counts by severity, highest first, without zeros.
     */
    public static function counts(array $findings): array
    {
        $counts = array_fill_keys(self::SEVERITIES, 0);
        foreach ($findings as $finding) {
            $counts[TeamVulnerabilities::severityOf($finding)]++;
        }

        return array_filter($counts);
    }

    /**
     * "2 critical, 1 high"
     *
     * @param  array<string, int>  $counts
     */
    public static function countsPhrase(array $counts): string
    {
        $parts = [];
        foreach (self::SEVERITIES as $severity) {
            if (($counts[$severity] ?? 0) > 0) {
                $parts[] = $counts[$severity].' '.$severity;
            }
        }

        return implode(', ', $parts);
    }

    /**
     * The first update the fix panel offers: a newer tag, a newer build of the same tag, or a newer base image.
     */
    public static function recommendedFix(ImageScan $scan, Model $owner): ?string
    {
        return array_values(self::betterCandidates($scan, $owner))[0] ?? null;
    }

    /**
     * The current scans of the owner, decoded once: fixable-now findings at the level (with the scan of each ID),
     * better candidates, every ID and alias found (lower case), and whether all candidate checks succeeded.
     *
     * @return array{scans: Collection<int, ImageScan>, fixable: list<Finding>, scanOf: array<string, ImageScan>, candidates: array<string, string>, present: array<string, true>, candidatesChecked: bool}
     */
    private static function current(Model $owner): array
    {
        $scans = ResourceScans::currentFor($owner);
        $ignores = IgnoreMatcher::forResource($owner);
        $fixable = [];
        $scanOf = [];
        $candidates = [];
        $present = [];
        $checked = true;

        foreach ($scans as $scan) {
            foreach (self::fixableNow($scan, $owner, $ignores) as $finding) {
                $fixable[] = $finding;
                $scanOf[$finding->id] ??= $scan;
            }
            $candidates += self::betterCandidates($scan, $owner);
            foreach (ResourceScans::findings($scan) as $finding) {
                foreach ([$finding->id, ...$finding->aliases] as $id) {
                    $present[mb_strtolower($id)] = true;
                }
            }
            // A failed check, or one that did not run yet, keeps the candidates the team was told about.
            $status = $scan->candidateStatus();
            if ($status === 'failed' || data_get($scan->candidates, 'base.status') === 'failed'
                || ($status === null && RefreshCandidates::needsCandidates($scan))) {
                $checked = false;
            }
        }

        return ['scans' => $scans, 'fixable' => $fixable, 'scanOf' => $scanOf, 'candidates' => $candidates, 'present' => $present, 'candidatesChecked' => $checked];
    }

    /**
     * Runs $change in a transaction with the state row of the owner locked (null when there is none).
     *
     * @template T
     *
     * @param  callable(?ImageScanNotificationState): T  $change
     * @return T
     */
    private static function locked(Model $owner, callable $change): mixed
    {
        return ImageScanNotificationState::query()->getConnection()->transaction(
            fn () => $change(self::stateQuery($owner)->lockForUpdate()->first()),
        );
    }

    /**
     * @return Builder<ImageScanNotificationState>
     */
    private static function stateQuery(Model $owner): Builder
    {
        return ImageScanNotificationState::query()
            ->where('resource_type', $owner->getMorphClass())
            ->where('resource_id', $owner->getKey());
    }

    /**
     * The baseline row. A row that another run created at the same time wins.
     *
     * @param  array{fixable: list<Finding>, candidates: array<string, string>}  $current
     */
    private static function createState(Model $owner, int $teamId, array $current, ?int $deploymentId): void
    {
        ImageScanNotificationState::query()->insertOrIgnore([
            'resource_type' => $owner->getMorphClass(),
            'resource_id' => $owner->getKey(),
            'team_id' => $teamId,
            'notified_ids' => json_encode(self::ids($current['fixable'])),
            'notified_candidates' => json_encode(self::capCandidates(array_keys($current['candidates']))),
            'is_baseline' => true,
            'baseline_deployment_queue_id' => $deploymentId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private static function teamId(Model $owner): ?int
    {
        $team = method_exists($owner, 'team') ? $owner->team() : null;

        return $team instanceof Team ? (int) $team->id : null;
    }

    /**
     * A scan of a pull request preview deployment.
     */
    private static function isPreview(?int $deploymentId): bool
    {
        return $deploymentId !== null
            && (int) ApplicationDeploymentQueue::query()->withoutGlobalScopes()->whereKey($deploymentId)->value('pull_request_id') !== 0;
    }

    /**
     * A deployment of the application is queued or running, so its current scans can miss the images it did not
     * scan yet.
     */
    private static function isDeploying(Model $owner): bool
    {
        return $owner instanceof Application
            && ApplicationDeploymentQueue::query()->withoutGlobalScopes()
                ->where('application_id', (string) $owner->getKey())
                ->where('pull_request_id', 0)
                ->whereIn('status', [ApplicationDeploymentStatus::QUEUED->value, ApplicationDeploymentStatus::IN_PROGRESS->value])
                ->exists();
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private static function updateText(string $imageRef, array $entry): ?string
    {
        try {
            $tag = ImageRef::parse((string) $entry['ref'])->tag;
        } catch (InvalidArgumentException) {
            return null;
        }

        return match ($entry['kind'] ?? null) {
            'line' => $tag === null ? null : "Update {$imageRef} to {$tag}.",
            'same_tag' => "Pull the newest build of {$imageRef}.",
            default => null,
        };
    }

    /**
     * Unique keys, the newest last, at most MAX_NOTIFIED_CANDIDATES (the oldest are dropped).
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    private static function capCandidates(array $keys): array
    {
        $unique = array_reverse(array_values(array_unique(array_reverse(array_map('strval', array_values($keys))))));

        return array_values(array_slice($unique, -self::MAX_NOTIFIED_CANDIDATES));
    }

    /**
     * @param  list<string>  $ids
     * @param  list<string>  $add
     * @return list<string>
     */
    private static function merge(array $ids, array $add): array
    {
        return array_values(array_unique([...array_map('strval', $ids), ...$add]));
    }

    /**
     * Lower-case IDs as keys, built once per state.
     *
     * @param  list<string>  $ids
     * @return array<string, true>
     */
    private static function idSet(array $ids): array
    {
        $set = [];
        foreach ($ids as $id) {
            $set[mb_strtolower((string) $id)] = true;
        }

        return $set;
    }

    /**
     * @param  array<string, true>  $set
     */
    private static function isIn(Finding $finding, array $set): bool
    {
        foreach ([$finding->id, ...$finding->aliases] as $id) {
            if (isset($set[mb_strtolower($id)])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<Finding>  $findings
     * @return list<string>
     */
    private static function ids(array $findings): array
    {
        return array_values(array_unique(array_map(fn (Finding $finding): string => $finding->id, $findings)));
    }
}
