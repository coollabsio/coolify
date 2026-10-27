<?php

namespace App\Services\ImageScan;

use App\Actions\ImageScan\RefreshCandidates;
use App\Actions\ImageScan\UpdateResourceImage;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\ImageScanStatus;
use App\Enums\SecurityActionType;
use App\Jobs\ImageScanAlertCheckJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ImageScan;
use App\Models\ImageScanNotificationState;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\Team;
use App\Notifications\ImageScan\ExploitedProblemFound;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Throwable;

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
 * - A problem with an exploit signal whose fix depends on the version or base check (no action yet, or an image
 *   update) waits for an outstanding check at most HOLD_MINUTES after the scan; ImageScanAlertCheckJob looks again
 *   when the wait ends. Without a check result the alert says that Coolify could not check for a fixed version.
 * - A rescan (not a deploy) of the deployment that started the baseline extends it only BASELINE_RESCAN_HOURS long.
 * - `held_digests`: digests that held new problems back while the resource needed a check (see ImageScanDigestJob).
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

    /** How long after its scan a check_needed (versions_unchecked) waits for the version check before a digest names it. */
    public const PENDING_HOURS = 24;

    /** How long after its scan an exploited problem without a fix waits for the version check before it is alerted. */
    public const HOLD_MINUTES = 60;

    /** A baseline that a rescan (not a deploy) continues ends this long after it started. */
    public const BASELINE_RESCAN_HOURS = 2;

    /** Actions that only the version or base check finds. A problem with another action never waits for the check. */
    private const CHECK_ACTIONS = [SecurityActionType::UpdateImage, SecurityActionType::PullLatest, SecurityActionType::ChangeBase];

    /**
     * After a scan of the owner completed, outside the scan lock: creates or extends the baseline, or alerts the
     * team right away for new relevant findings with an exploit signal.
     *
     * @param  ?int  $deploymentId  The deployment that ran this scan (deploy path); null for rescans, Scan now, and
     *                              scans after a start, which use the deployment the row is linked to.
     * @param  bool  $candidateCheck  Called after a version check of the scan (or when its wait ended) instead of the scan itself.
     * @param  bool  $checkGaveUp  The version check of the scan will not store a result, so its findings do not wait.
     */
    public static function afterScan(ImageScan $scan, ?int $deploymentId = null, bool $candidateCheck = false, bool $checkGaveUp = false): void
    {
        if (! $scan->exists || $scan->status !== ImageScanStatus::Completed) {
            return;
        }

        $owner = $scan->resource;
        $teamId = $owner instanceof Model ? self::teamId($owner) : null;
        $explicit = $deploymentId !== null;
        $deploymentId ??= $scan->deployment_queue_id !== null ? (int) $scan->deployment_queue_id : null;
        if ($teamId === null || self::isPreview($deploymentId)) {
            return;
        }
        $released = $checkGaveUp ? (int) $scan->getKey() : null;

        // The current scans are read under the lock, so a scan that lands meanwhile is not recorded without notice.
        [$alert, $held] = self::locked($owner, function (?ImageScanNotificationState $state) use ($owner, $teamId, $scan, $deploymentId, $explicit, $candidateCheck, $released): array {
            $current = self::current($owner, released: $released);
            if ($current['scans']->isEmpty()) {
                return [[], []];
            }

            if (! $state instanceof ImageScanNotificationState) {
                self::createState($owner, $teamId, $current, $deploymentId);

                return [[], []];
            }

            if ($state->is_baseline && ($candidateCheck || self::extendsBaseline($state, $deploymentId, $explicit))) {
                // A version check adds only what the checked scan has; a redeploy meanwhile is not part of the baseline.
                $recorded = $candidateCheck ? self::current($owner, collect([$scan]), $released) : $current;
                $state->update([
                    'notified_ids' => self::merge((array) $state->notified_ids, self::ids(array_column($recorded['signal'], 'finding'))),
                    'digest_ids' => self::merge((array) $state->digest_ids, self::ids($recorded['fixable'])),
                    'notified_candidates' => self::capCandidates([...(array) $state->notified_candidates, ...array_keys($recorded['candidates'])]),
                ]);

                return [[], []];
            }

            $notified = self::idSet((array) $state->notified_ids);
            $alert = [];
            $held = [];
            foreach ($current['signal'] as $entry) {
                if (self::isIn($entry['finding'], $notified)) {
                    continue;
                }
                if ($entry['held']) {
                    $held[$entry['scan_id']] = true;

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

            return [$alert, array_keys($held)];
        });

        foreach ($held as $scanId) {
            // The wait ends at the latest HOLD_MINUTES after the scan, also when the check never stores a result.
            ImageScanAlertCheckJob::dispatch((int) $scanId)->delay(now()->addMinutes(self::HOLD_MINUTES + 1));
        }

        $team = $alert === [] ? null : Team::query()->find($teamId);
        if ($team instanceof Team) {
            $team->notify(new ExploitedProblemFound($owner, $alert));
        }
    }

    /**
     * Looks at the alert of a scan again: when its version check stored a result, gave up, or its wait ended.
     *
     * @param  bool  $checkGaveUp  The version check will not store a result (the job failed, or cannot run).
     */
    public static function reevaluate(ImageScan $scan, bool $checkGaveUp = false): void
    {
        try {
            self::afterScan($scan, candidateCheck: true, checkGaveUp: $checkGaveUp);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * What a digest sees for one owner, and what it records after sending (see `applyDigest()`). Nothing is new for a
     * baseline or an owner without a state row; the digest records what it sees.
     *
     * - `newFixable`: relevant findings with an action that no digest named yet; `newSignalFixable` the ones of them
     *   with an exploit signal.
     * - `newCandidates`: better images no digest named yet; `newSignalCandidates` the ones that fix a finding with an
     *   exploit signal.
     * - `pending`: a current scan waits for its version check (see `versionCheckPending()`).
     * - `checkReported`, `heldDigests`: from the state row.
     *
     * @return array{baseline: bool, newFixable: list<Finding>, newSignalFixable: list<Finding>, newCandidates: list<string>, newSignalCandidates: list<string>, pending: bool, checkReported: bool, heldDigests: int, mark: array<string, mixed>}|null
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
        $newCandidates = $baseline ? [] : array_values(array_filter(array_keys($current['candidates']), fn (string $key): bool => ! isset($named[$key])));

        $signalIds = [];
        foreach ($current['signal'] as $entry) {
            foreach ([$entry['finding']->id, ...$entry['finding']->aliases] as $id) {
                $signalIds[mb_strtolower($id)] = true;
            }
        }
        $newSignalFixable = array_values(array_filter($newFixable, fn (Finding $finding): bool => self::isIn($finding, $signalIds)));
        $newSignalCandidates = array_values(array_filter($newCandidates, fn (string $key): bool => array_intersect_key($current['candidates'][$key], $signalIds) !== []));
        $deploying = self::isDeploying($owner);

        return [
            'baseline' => $baseline,
            'newFixable' => $newFixable,
            'newSignalFixable' => $newSignalFixable,
            'newCandidates' => $newCandidates,
            'newSignalCandidates' => $newSignalCandidates,
            'pending' => $current['pending'],
            'checkReported' => (bool) $state?->check_reported,
            'heldDigests' => (int) $state?->held_digests,
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
                'add_candidates' => $baseline ? array_keys($current['candidates']) : $newCandidates,
                'present' => array_keys($current['present']),
                'offered' => array_keys($current['candidates']),
                'prune_ids' => ! $deploying,
                'prune_candidates' => ! $deploying && $current['candidatesChecked'],
                'check_reported' => null,
                'held_digests' => null,
            ],
        ];
    }

    /**
     * Records what a digest covered under the row lock, and ends a baseline. IDs and candidates that the state had
     * when the digest read it and that no current scan has any more are removed; ones added meanwhile stay.
     *
     * @param  array<string, mixed>  $mark  From `digestPlan()`; `check_reported` and `held_digests` null keep the stored value.
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
                    'held_digests' => (int) ($mark['held_digests'] ?? 0),
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
                'held_digests' => $mark['held_digests'] ?? $state->held_digests,
            ]);
        });
    }

    /**
     * The better images the resource can switch to (`ref@digest`) and a better base image (`base:{ref}`), each with the
     * IDs it fixes (lower case, as keys).
     *
     * @return array<string, array<string, true>>
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
                    $candidates[$entry['ref'].'@'.($entry['digest'] ?? '')] = self::idSet((array) ($entry['fixed_ids'] ?? []));
                }
            }
        }

        $base = BaseImageCheck::baseImage($scan);
        if ($base !== null) {
            $candidates['base:'.$base['to']] = self::idSet((array) (BaseImageCheck::betterBase($scan)['entry']['fixed_ids'] ?? []));
        }

        return $candidates;
    }

    /**
     * For the digest: the version check is needed and stored no result since the row was created, less than
     * PENDING_HOURS ago, so a check_needed (versions_unchecked) is not settled yet.
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
     * For alerts: whether the version check of a scan is outstanding (needed, no result since the scan, and queued
     * again because the last result is not fresh), and whether it is incomplete (outstanding, older than the scan,
     * failed, or without a checked result), so Coolify cannot say that no fixed version exists.
     *
     * @return array{outstanding: bool, incomplete: bool}
     */
    public static function checkState(ImageScan $scan, Model $owner): array
    {
        if (! RefreshCandidates::needsCandidates($scan)) {
            return ['outstanding' => false, 'incomplete' => false];
        }

        $checkedAt = data_get($scan->candidates, 'checked_at');
        try {
            $checkedAt = is_string($checkedAt) ? CarbonImmutable::parse($checkedAt) : null;
        } catch (Throwable) {
            $checkedAt = null;
        }
        $afterScan = $checkedAt !== null && ($scan->scanned_at === null || $checkedAt->gte($scan->scanned_at->copy()->startOfSecond()));

        return [
            'outstanding' => ! $afterScan && ! RefreshCandidates::isFresh($scan),
            'incomplete' => ! $afterScan || ! SecurityStatus::versionsChecked($scan, $owner),
        ];
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
     * - `signal`: relevant findings with an exploit signal, with the action that fixes each (null: none), whether it
     *   waits for the version check (`held`: no action that does not depend on the check, the check is outstanding,
     *   and the scan is less than HOLD_MINUTES old), and whether the check is incomplete;
     * - `fixable`: relevant findings that an action fixes;
     * - `candidates`: better images with the IDs each fixes; `present`: every ID and alias found (lower case);
     * - `candidatesChecked`: every version check succeeded; `pending`: see `versionCheckPending()`.
     *
     * @param  Collection<int, ImageScan>|null  $only
     * @param  ?int  $released  A scan whose findings do not wait for its check any more.
     * @return array{scans: Collection<int, ImageScan>, signal: list<array{finding: Finding, action: ?SecurityAction, held: bool, incomplete: bool, scan_id: int}>, fixable: list<Finding>, candidates: array<string, array<string, true>>, present: array<string, true>, candidatesChecked: bool, pending: bool}
     */
    private static function current(Model $owner, ?Collection $only = null, ?int $released = null): array
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
            $anyPending = $anyPending || self::versionCheckPending($scan);
            $check = null;
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
                if (SecurityRules::exploitSignal($finding) === null) {
                    continue;
                }

                $check ??= self::checkState($scan, $owner);
                $waits = ! $action instanceof SecurityAction || in_array($action->type, self::CHECK_ACTIONS, true);
                $signal[] = [
                    'finding' => $finding,
                    'action' => $action,
                    'held' => $waits && $check['outstanding'] && (int) $scan->getKey() !== $released
                        && $scan->scanned_at !== null && $scan->scanned_at->gt(now()->subMinutes(self::HOLD_MINUTES)),
                    'incomplete' => $check['incomplete'],
                    'scan_id' => (int) $scan->getKey(),
                ];
            }
            $candidates += self::betterCandidates($scan, $owner);

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
            'candidates' => $candidates,
            'present' => $present,
            'candidatesChecked' => $checked,
            'pending' => $anyPending,
        ];
    }

    /**
     * A scan of the deployment that started the baseline adds to it: always on the deploy path, and on a rescan or
     * Scan now (the deployment of the row) only within BASELINE_RESCAN_HOURS of the start, so the first rescan of a
     * Compose application is one baseline while a later rescan of the same row alerts.
     */
    private static function extendsBaseline(ImageScanNotificationState $state, ?int $deploymentId, bool $explicit): bool
    {
        if ($deploymentId === null || $deploymentId !== $state->baseline_deployment_queue_id) {
            return false;
        }

        return $explicit || ($state->created_at !== null && $state->created_at->gt(now()->subHours(self::BASELINE_RESCAN_HOURS)));
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
     * @param  array{signal: list<array{finding: Finding}>, fixable: list<Finding>, candidates: array<string, array<string, true>>}  $current
     */
    private static function createState(Model $owner, int $teamId, array $current, ?int $deploymentId): void
    {
        ImageScanNotificationState::query()->insertOrIgnore([
            'resource_type' => $owner->getMorphClass(),
            'resource_id' => $owner->getKey(),
            'team_id' => $teamId,
            'notified_ids' => json_encode(self::ids(array_column($current['signal'], 'finding'))),
            'digest_ids' => json_encode(self::ids($current['fixable'])),
            'notified_candidates' => json_encode(self::capCandidates(array_keys($current['candidates']))),
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
