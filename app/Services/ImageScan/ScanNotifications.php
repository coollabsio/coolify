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
use App\Notifications\ImageScan\ExploitedProblemFound;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * What the image security notifications told a team (spec 15.8), kept in one `image_scan_notification_states` row
 * per scan owner (application, service application or database, standalone database).
 *
 * - The current scans of an owner are `ResourceScans::currentFor()`: completed, never previews.
 * - `notified_ids`: relevant findings with an exploit signal that an immediate alert named. A finding is new when
 *   none of its ID and aliases is in it, so an EPSS that moves around the threshold does not alert again.
 * - `digest_ids`: relevant findings with an action that a digest named, or an alert named with its action.
 * - `notified_candidates`: better images (and base images) that a digest covered.
 * - `check_reported`: a digest said that the resource needs a check.
 * - Without a state row the owner is a baseline: the row is created from what the current scans have, and nothing is
 *   sent. The baseline also records, without notifying, the other images of the same deployment (Compose) and the
 *   version checks that follow. It ends with the next scan that is not part of that deployment, or with the next
 *   digest.
 * - Right after a scan, before its version check stored a result, the actions of the scan are not known yet. Its
 *   findings wait for the check (at most PENDING_HOURS), so an alert does not say "no fix yet" for a fixed problem.
 * - The digest removes IDs and candidates that no current scan has any more, so a regression notifies again. It
 *   skips that while a deployment of the application runs (its current scans can be incomplete), and it keeps the
 *   candidates while a version check failed.
 * - Every write locks the state row, so the alert and the digest never overwrite each other.
 * - Preview scans never notify and never change the state.
 */
final class ScanNotifications
{
    /** Candidates kept per resource; the oldest go first. */
    public const MAX_NOTIFIED_CANDIDATES = 200;

    /** How long a scan waits for its version check before its findings count without it. */
    public const PENDING_HOURS = 24;

    /**
     * After a scan of the owner completed, outside the scan lock: creates or extends the baseline, or alerts the
     * team right away for new relevant findings with an exploit signal.
     *
     * @param  ?int  $deploymentId  The deployment that ran this scan; null for rescans and scans after a start.
     * @param  bool  $candidateCheck  Called after a version check of the scan instead of the scan itself.
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
        $alert = self::locked($owner, function (?ImageScanNotificationState $state) use ($owner, $teamId, $scan, $deploymentId, $candidateCheck): array {
            $current = self::current($owner);
            if ($current['scans']->isEmpty()) {
                return [];
            }

            if (! $state instanceof ImageScanNotificationState) {
                self::createState($owner, $teamId, $current, $deploymentId);

                return [];
            }

            if ($state->is_baseline && ($candidateCheck || ($deploymentId !== null && $deploymentId === $state->baseline_deployment_queue_id))) {
                // A version check adds only what the checked scan has; a redeploy meanwhile is not part of the baseline.
                $recorded = $candidateCheck ? self::current($owner, collect([$scan])) : $current;
                $state->update([
                    'notified_ids' => self::merge((array) $state->notified_ids, self::ids(array_column($recorded['signal'], 'finding'))),
                    'digest_ids' => self::merge((array) $state->digest_ids, self::ids($recorded['fixable'])),
                    'notified_candidates' => self::capCandidates([...(array) $state->notified_candidates, ...$recorded['candidates']]),
                ]);

                return [];
            }

            $notified = self::idSet((array) $state->notified_ids);
            $alert = [];
            foreach ($current['signal'] as $entry) {
                if ($entry['pending'] || self::isIn($entry['finding'], $notified)) {
                    continue;
                }
                $alert[] = $entry;
                foreach ([$entry['finding']->id, ...$entry['finding']->aliases] as $id) {
                    $notified[mb_strtolower($id)] = true;
                }
            }
            $withAction = array_values(array_filter($alert, fn (array $entry): bool => $entry['action'] instanceof SecurityAction));
            $state->update([
                'is_baseline' => false,
                'notified_ids' => self::merge((array) $state->notified_ids, self::ids(array_column($alert, 'finding'))),
                'digest_ids' => self::merge((array) $state->digest_ids, self::ids(array_column($withAction, 'finding'))),
            ]);

            return $alert;
        });

        $team = $alert === [] ? null : Team::query()->find($teamId);
        if ($team instanceof Team) {
            $team->notify(new ExploitedProblemFound($owner, $alert));
        }
    }

    /**
     * What a digest sees for one owner, and what it records after sending (see `applyDigest()`). Nothing is new for a
     * baseline or an owner without a state row; the digest records what it sees.
     *
     * `newFixable`: relevant findings with an action that no digest named yet. `newCandidates`: better images no
     * digest named yet. `pending`: a current scan still waits for its version check.
     *
     * @return array{baseline: bool, newFixable: list<Finding>, newCandidates: list<string>, pending: bool, checkReported: bool, mark: array<string, mixed>}|null
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
        $reported = self::idSet((array) $state?->digest_ids);
        $named = array_flip((array) $state?->notified_candidates);
        $newFixable = $baseline ? [] : array_values(array_filter($current['fixable'], fn (Finding $finding): bool => ! self::isIn($finding, $reported)));
        $newCandidates = $baseline ? [] : array_values(array_filter($current['candidates'], fn (string $key): bool => ! isset($named[$key])));

        return [
            'baseline' => $baseline,
            'newFixable' => $newFixable,
            'newCandidates' => $newCandidates,
            'pending' => $current['pending'],
            'checkReported' => (bool) $state?->check_reported,
            'mark' => [
                'baseline' => $baseline,
                'owner_type' => $owner->getMorphClass(),
                'owner_id' => $owner->getKey(),
                'team_id' => $teamId,
                'read_ids' => array_values((array) $state?->notified_ids),
                'read_digest_ids' => array_values((array) $state?->digest_ids),
                'read_candidates' => array_values((array) $state?->notified_candidates),
                'add_ids' => $baseline ? self::ids(array_column($current['signal'], 'finding')) : [],
                'add_digest_ids' => self::ids($baseline ? $current['fixable'] : $newFixable),
                'add_candidates' => $baseline ? $current['candidates'] : $newCandidates,
                'present' => array_keys($current['present']),
                'offered' => $current['candidates'],
                'prune_ids' => ! self::isDeploying($owner),
                'prune_candidates' => ! self::isDeploying($owner) && $current['candidatesChecked'],
                'check_reported' => null,
            ],
        ];
    }

    /**
     * Records what a digest covered under the row lock, and ends a baseline. IDs and candidates that the state had
     * when the digest read it and that no current scan has any more are removed; ones added meanwhile stay.
     *
     * @param  array<string, mixed>  $mark  From `digestPlan()`; `check_reported` null keeps the stored value.
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
                    'digest_ids' => json_encode($mark['add_digest_ids']),
                    'notified_candidates' => json_encode(self::capCandidates($mark['add_candidates'])),
                    'check_reported' => (bool) ($mark['check_reported'] ?? false),
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
                $mark['add_digest_ids'] = [];
                $mark['add_candidates'] = [];
            }

            $present = array_flip($mark['present']);
            $prune = function (array $stored, array $add, array $read) use ($mark, $present): array {
                $read = array_flip(array_map(fn ($id): string => mb_strtolower((string) $id), $read));

                return array_values(array_filter(
                    self::merge($stored, $add),
                    fn (string $id): bool => ! $mark['prune_ids'] || ! isset($read[mb_strtolower($id)]) || isset($present[mb_strtolower($id)]),
                ));
            };

            $offered = array_flip($mark['offered']);
            $readCandidates = array_flip($mark['read_candidates']);
            $candidates = array_filter(
                [...(array) $state->notified_candidates, ...$mark['add_candidates']],
                fn (string $key): bool => ! $mark['prune_candidates'] || ! isset($readCandidates[$key]) || isset($offered[$key]),
            );

            $state->update([
                'is_baseline' => false,
                'notified_ids' => $prune((array) $state->notified_ids, $mark['add_ids'], $mark['read_ids']),
                'digest_ids' => $prune((array) $state->digest_ids, $mark['add_digest_ids'], $mark['read_digest_ids'] ?? []),
                'notified_candidates' => self::capCandidates($candidates),
                'check_reported' => $mark['check_reported'] ?? $state->check_reported,
            ]);
        });
    }

    /**
     * The keys of the better images the resource can switch to (`ref@digest`), and of a better base image.
     *
     * @return list<string>
     */
    public static function betterCandidates(ImageScan $scan, Model $owner): array
    {
        if ((int) $owner->getKey() === 0) {
            return [];
        }

        $candidates = [];
        if (UpdateResourceImage::blockedReason($scan, $owner) === null) {
            foreach ($scan->updateCandidates() as $entry) {
                if (in_array($entry['kind'] ?? null, ['line', 'same_tag'], true)) {
                    $candidates[] = $entry['ref'].'@'.($entry['digest'] ?? '');
                }
            }
        }

        $base = BaseImageCheck::baseImage($scan);
        if ($base !== null) {
            $candidates[] = 'base:'.$base['to'];
        }

        return $candidates;
    }

    /**
     * The scan waits for its version check: the check is needed and stored no result since the row was created,
     * less than PENDING_HOURS ago.
     */
    public static function versionCheckPending(ImageScan $scan): bool
    {
        $created = $scan->created_at ?? $scan->scanned_at;

        return $created !== null
            && $created->gt(now()->subHours(self::PENDING_HOURS))
            && ! is_string(data_get($scan->candidates, 'checked_at'))
            && RefreshCandidates::needsCandidates($scan);
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
     * The current scans of the owner (or $only), decoded once:
     * - `signal`: relevant findings with an exploit signal, with the action that fixes each (null: none) and whether
     *   the scan waits for its version check;
     * - `fixable`: relevant findings that an action fixes;
     * - `candidates`: keys of better images; `present`: every ID and alias found (lower case);
     * - `candidatesChecked`: every version check succeeded; `pending`: a scan waits for its version check.
     *
     * @param  Collection<int, ImageScan>|null  $only
     * @return array{scans: Collection<int, ImageScan>, signal: list<array{finding: Finding, action: ?SecurityAction, pending: bool}>, fixable: list<Finding>, candidates: list<string>, present: array<string, true>, candidatesChecked: bool, pending: bool}
     */
    private static function current(Model $owner, ?Collection $only = null): array
    {
        $scans = $only ?? ResourceScans::currentFor($owner);
        $ignores = IgnoreMatcher::forResource($owner);
        $signal = [];
        $fixable = [];
        $candidates = [];
        $present = [];
        $checked = true;
        $anyPending = false;

        foreach ($scans as $scan) {
            $scan->setRelation('resource', $owner);
            $byFinding = SecurityActions::forScan($scan, $ignores)['byFinding'];
            $pending = self::versionCheckPending($scan);
            $anyPending = $anyPending || $pending;
            foreach (ResourceScans::findings($scan) as $finding) {
                foreach ([$finding->id, ...$finding->aliases] as $id) {
                    $present[mb_strtolower($id)] = true;
                }
                if (! SecurityRules::isRelevant($finding, $ignores)) {
                    continue;
                }

                $action = $byFinding[$finding->id] ?? null;
                if ($action instanceof SecurityAction) {
                    $fixable[] = $finding;
                }
                if (SecurityRules::exploitSignal($finding) !== null) {
                    $signal[] = ['finding' => $finding, 'action' => $action, 'pending' => $pending];
                }
            }
            $candidates = [...$candidates, ...self::betterCandidates($scan, $owner)];

            // A failed check, or one that did not run yet, keeps the candidates the team was told about.
            $status = $scan->candidateStatus();
            if ($status === 'failed' || data_get($scan->candidates, 'base.status') === 'failed'
                || ($status === null && RefreshCandidates::needsCandidates($scan))) {
                $checked = false;
            }
        }

        return [
            'scans' => $scans,
            'signal' => $signal,
            'fixable' => $fixable,
            'candidates' => array_values(array_unique($candidates)),
            'present' => $present,
            'candidatesChecked' => $checked,
            'pending' => $anyPending,
        ];
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
     * @param  array{signal: list<array{finding: Finding}>, fixable: list<Finding>, candidates: list<string>}  $current
     */
    private static function createState(Model $owner, int $teamId, array $current, ?int $deploymentId): void
    {
        ImageScanNotificationState::query()->insertOrIgnore([
            'resource_type' => $owner->getMorphClass(),
            'resource_id' => $owner->getKey(),
            'team_id' => $teamId,
            'notified_ids' => json_encode(self::ids(array_column($current['signal'], 'finding'))),
            'digest_ids' => json_encode(self::ids($current['fixable'])),
            'notified_candidates' => json_encode(self::capCandidates($current['candidates'])),
            'is_baseline' => true,
            'baseline_deployment_queue_id' => $deploymentId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * The team that owns the resource. Coolify's own resources (`id = 0`) belong to the root team, so only it is
     * notified about them.
     */
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
