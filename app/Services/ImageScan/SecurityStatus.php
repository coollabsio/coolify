<?php

namespace App\Services\ImageScan;

use App\Enums\ImageScanStatus;
use App\Enums\SecurityActionType;
use App\Enums\SecurityStatusType;
use App\Models\Application;
use App\Models\ImageScan;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

/**
 * Decides one security status per image and per resource (spec 15.6) from stored data only: the findings, the
 * version and base checks, the scan times, and the resource's configuration. No remote calls.
 *
 * Each completed scan that is not a preview stores its status in `summary['security']` (`store()`), written by the
 * scan and by every summary refresh. `stored()` combines the stored statuses of the current scans of a resource
 * without reading the findings: it adds the counts and the per-action data, merges the actions (and, for a service,
 * the updates of images that share the current tag to one new tag), keeps the check reasons that do not depend on
 * the time (database age, running image, version check), adds the age of the oldest current result and the images
 * that are not checked, and then applies the rules again. `forResource()` does the same with statuses computed now.
 *
 * Images that are not checked: an application or database of a service that Coolify scans (see
 * `ResourceScans::scanTargets()`) without a current scan, a current scan without a stored status (`stored()` only),
 * and the images of a Compose application's current deployment without a completed result
 * (`ResourceScans::uncheckedComposeImages()`).
 */
final class SecurityStatus
{
    public const STALE_AFTER_DAYS = 2;

    public const DATABASE_MAX_AGE_DAYS = 5;

    public const WHY_LIMIT = 3;

    public const REASON_IMAGES_UNCHECKED = 'images_unchecked';

    public const REASON_STALE = 'stale';

    public const REASON_DB_OUTDATED = 'db_outdated';

    public const REASON_IMAGE_CHANGED = 'image_changed';

    public const REASON_VERSIONS_UNCHECKED = 'versions_unchecked';

    public const REASON_TRUNCATED = 'truncated';

    public const NOT_SCANNED = 'not_scanned';

    /** The check_needed reasons in the order they are shown. */
    private const REASON_ORDER = [
        self::REASON_IMAGES_UNCHECKED,
        self::REASON_TRUNCATED,
        self::REASON_STALE,
        self::REASON_DB_OUTDATED,
        self::REASON_IMAGE_CHANGED,
        self::REASON_VERSIONS_UNCHECKED,
    ];

    /** Results of a version or base check that count as checked. */
    private const CHECKED_STATUSES = ['ok', 'no_better', 'cannot_compare'];

    public const STORED_COLUMNS = ['id', 'resource_type', 'resource_id', 'deployment_queue_id', 'image_ref', 'summary', 'scanned_at'];

    private const COUNT_KEYS = ['signal_fixed', 'signal_unfixed', 'kev_fixed', 'kev_unfixed', 'critical_fixed', 'high_fixed', 'relevant_fixed', 'builder_unfixed'];

    /**
     * The status of one scanned image, with the age of the result. `$ignores` is the ignore matcher of the scan's
     * owner, when the caller has it.
     */
    public static function forScan(ImageScan $scan, ?IgnoreMatcher $ignores = null): ResourceSecurity
    {
        if ($scan->status !== ImageScanStatus::Completed || ! $scan->resource instanceof Model) {
            return self::notChecked(self::NOT_SCANNED);
        }

        return self::evaluate($scan, $ignores);
    }

    /**
     * The status of a resource from its current scans, computed now. A service combines the images of its
     * applications and databases. A caller that loaded the current scans of the owners (`ResourceScans::current()`,
     * with `resource` set) passes them, and the statuses of `forScan()` it computed already, keyed by scan UUID.
     *
     * @param  Collection<int, ImageScan>|null  $currentScans
     * @param  array<string, ResourceSecurity>  $imageStatuses
     */
    public static function forResource(Model $resource, ?Collection $currentScans = null, array $imageStatuses = []): ResourceSecurity
    {
        $off = ImageScanPolicyResolver::offState($resource);
        if ($off !== null) {
            return self::notChecked($off);
        }

        $images = self::statusOwners($resource, ResourceScans::owners($resource))
            ->map(function (Model $owner) use ($currentScans, $imageStatuses): array {
                $scans = $currentScans === null
                    ? ResourceScans::currentFor($owner)
                    : $currentScans->filter(fn (ImageScan $scan): bool => $scan->resource_type === $owner->getMorphClass() && (string) $scan->resource_id === (string) $owner->getKey())->values();

                return [
                    'parts' => $scans->map(fn (ImageScan $scan): ResourceSecurity => $imageStatuses[(string) $scan->uuid] ?? self::evaluate($scan))->all(),
                    'unchecked' => ResourceScans::uncheckedComposeImages($owner, $scans),
                ];
            })
            ->all();

        return self::combineImages($images, $resource instanceof Service);
    }

    /**
     * The status of a resource from the stored statuses of its current scans. A scan without a stored status counts
     * as an image that is not checked.
     */
    public static function stored(Model $resource): ResourceSecurity
    {
        return self::storedMany(collect([$resource]))[ResourceScans::key($resource)];
    }

    /**
     * `stored()` for many resources with a fixed number of queries, also for many Compose applications.
     *
     * @param  Collection<int, Model>  $resources
     * @param  Collection<string, Collection<int, ImageScan>>|null  $scans  The current scans of the owners of the scanned
     *                                                                      resources (`ResourceScans::currentForMany()` with
     *                                                                      at least STORED_COLUMNS), when the caller has them.
     * @return array<string, ResourceSecurity> Keyed by `ResourceScans::key()`.
     */
    public static function storedMany(Collection $resources, ?Collection $scans = null): array
    {
        $resources = $resources->values();
        if ($resources->isEmpty()) {
            return [];
        }

        ResourceScans::loadScanRelations($resources);
        $offStates = $resources->mapWithKeys(fn (Model $resource): array => [ResourceScans::key($resource) => ImageScanPolicyResolver::offState($resource)]);
        $scanned = $resources->filter(fn (Model $resource): bool => $offStates[ResourceScans::key($resource)] === null)->values();
        $owners = ResourceScans::ownersOfMany($scanned);
        $scans ??= ResourceScans::currentForMany($owners->flatten(1), self::STORED_COLUMNS);
        $composeRows = ResourceScans::composeDeploymentRows($owners->flatten(1), $scans);

        $result = [];
        foreach ($resources as $resource) {
            $key = ResourceScans::key($resource);
            if ($offStates[$key] !== null) {
                $result[$key] = self::notChecked($offStates[$key]);

                continue;
            }

            $images = self::statusOwners($resource, $owners->get($key, collect()))
                ->map(function (Model $owner) use ($scans, $composeRows): array {
                    $ownerScans = $scans->get(ResourceScans::key($owner), collect());

                    return [
                        'parts' => $ownerScans->map(fn (ImageScan $scan): ?ResourceSecurity => is_array(data_get($scan->summary, 'security'))
                            ? ResourceSecurity::fromArray(data_get($scan->summary, 'security'))
                            : null)->all(),
                        'unchecked' => ResourceScans::uncheckedComposeImages($owner, $ownerScans, $composeRows),
                    ];
                })
                ->all();
            $result[$key] = self::combineImages($images, $resource instanceof Service);
        }

        return $result;
    }

    /**
     * Adds the status of a completed scan to its summary (not saved). Preview scans get none: their actions would
     * compare with the scans of the application.
     */
    public static function store(ImageScan $scan): void
    {
        if ($scan->status !== ImageScanStatus::Completed || ! $scan->resource instanceof Model) {
            return;
        }

        $summary = (array) $scan->summary;
        unset($summary['security']);
        if ($scan->deployment_queue_id === null || (int) ($scan->deploymentQueue?->pull_request_id ?? 0) === 0) {
            try {
                $summary['security'] = self::evaluate($scan)->toArray();
            } catch (Throwable $e) {
                report($e);
            }
        }
        $scan->summary = $summary;
    }

    /**
     * A finding of a builder image that a builder update would fix: an OS package with a fixed version.
     */
    private static function builderCanFix(Finding $finding): bool
    {
        return in_array(strtolower($finding->type), ['apk', 'deb', 'rpm'], true)
            && $finding->fixState === 'fixed'
            && ($finding->fixedIn ?? '') !== '';
    }

    private static function notChecked(string $reason): ResourceSecurity
    {
        return new ResourceSecurity(status: SecurityStatusType::NotChecked, checkReason: $reason);
    }

    /**
     * The owners whose images count for the status: the applications and databases of a service that Coolify scans,
     * else the resource itself.
     *
     * @param  Collection<int, Model>  $owners
     * @return Collection<int, Model>
     */
    private static function statusOwners(Model $resource, Collection $owners): Collection
    {
        return $resource instanceof Service
            ? $owners->filter(fn (Model $owner): bool => ResourceScans::isScanTarget($owner))->values()
            : $owners;
    }

    /**
     * Per scan owner: the status of each current scan (null: no stored status) and the number of its images without a
     * result. An owner without a current scan is one image that is not checked.
     *
     * @param  list<array{parts: list<?ResourceSecurity>, unchecked: int}>  $images
     */
    private static function combineImages(array $images, bool $isService): ResourceSecurity
    {
        $total = 0;
        $parts = [];
        foreach ($images as $owner) {
            $total += max(1, count($owner['parts'])) + $owner['unchecked'];
            foreach ($owner['parts'] as $part) {
                if ($part instanceof ResourceSecurity) {
                    $parts[] = $part;
                }
            }
        }

        if ($parts === []) {
            return self::notChecked(self::NOT_SCANNED);
        }

        return self::resolve($parts, ['checked' => count($parts), 'total' => $total], $isService);
    }

    /**
     * The status of one scan before it is combined with other images.
     */
    private static function evaluate(ImageScan $scan, ?IgnoreMatcher $ignores = null): ResourceSecurity
    {
        /** @var Model $owner */
        $owner = $scan->resource;
        $ignores ??= IgnoreMatcher::forResource($owner);
        $result = SecurityActions::forScan($scan, $ignores);
        $actions = $result['actions'];
        $indexes = [];
        foreach ($actions as $index => $action) {
            $indexes[spl_object_id($action)] = $index;
        }

        $ownerData = ['owner' => self::ownerName($owner), 'owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->getKey()];
        $stats = array_map(fn (): array => [...self::emptyStats(), ...$ownerData], $actions);
        $counts = array_fill_keys(self::COUNT_KEYS, 0);
        $unfixed = [];
        $seen = [];
        $hasRelevant = false;
        $isBuilder = SecurityActions::imageKind($scan, $owner) === 'builder';
        foreach (ResourceScans::findings($scan) as $finding) {
            $key = mb_strtolower($finding->id);
            if (isset($seen[$key]) || ! SecurityRules::isRelevant($finding, $ignores)) {
                continue;
            }
            $seen[$key] = true;
            $hasRelevant = true;

            $signal = SecurityRules::exploitSignal($finding);
            $severity = strtolower($finding->severity);
            $action = $result['byFinding'][$finding->id] ?? null;
            $why = self::whyFor($finding, $signal);

            if (! $action instanceof SecurityAction) {
                if ($signal !== null) {
                    $counts['signal_unfixed']++;
                    $counts['kev_unfixed'] += $signal === SecurityRules::EXPLOITED ? 1 : 0;
                    $counts['builder_unfixed'] += $isBuilder && self::builderCanFix($finding) ? 1 : 0;
                    $unfixed[] = $why;
                }

                continue;
            }

            $index = $indexes[spl_object_id($action)];
            $stats[$index]['relevant']++;
            $stats[$index]['why'][] = $why;
            $counts['relevant_fixed']++;
            if ($signal !== null) {
                $stats[$index]['signal']++;
                $counts['signal_fixed']++;
            }
            if ($signal === SecurityRules::EXPLOITED) {
                $stats[$index]['kev']++;
                $counts['kev_fixed']++;
            }
            if (in_array($severity, ['critical', 'high'], true)) {
                $stats[$index][$severity]++;
                $counts[$severity.'_fixed']++;
            }
        }

        // Leaving an end-of-life OS counts as one critical relevant fix (spec 15.3).
        if ($result['eol'] instanceof SecurityAction) {
            $index = $indexes[spl_object_id($result['eol'])];
            $stats[$index]['eol'] = 1;
            $stats[$index]['critical']++;
            $stats[$index]['relevant']++;
            $counts['critical_fixed']++;
            $counts['relevant_fixed']++;
        }
        foreach ($stats as $index => $entry) {
            $stats[$index]['why'] = self::topWhy($entry['why']);
        }

        $eolDistro = data_get($scan->summary, 'eol_distro');
        $eol = is_array($eolDistro)
            ? ['name' => (string) ($eolDistro['name'] ?? ''), 'version' => (string) ($eolDistro['version'] ?? ''), 'fixed' => $result['eol'] !== null]
            : null;

        $reasons = [];
        if ((bool) data_get($scan->summary, 'truncated', false)) {
            $reasons[] = self::REASON_TRUNCATED;
        }
        if (self::databaseWasOld($scan)) {
            $reasons[] = self::REASON_DB_OUTDATED;
        }
        if (ResourceScans::runsOtherImage($scan, $owner)) {
            $reasons[] = self::REASON_IMAGE_CHANGED;
        }
        if (($hasRelevant || $eol !== null) && ! self::versionsChecked($scan, $owner)) {
            $reasons[] = self::REASON_VERSIONS_UNCHECKED;
        }

        return self::resolve([new ResourceSecurity(
            status: SecurityStatusType::Ok,
            actions: $actions,
            eol: $eol,
            checkedAt: $scan->scanned_at === null ? null : CarbonImmutable::instance($scan->scanned_at),
            facts: ['reasons' => $reasons, 'counts' => $counts, 'action_stats' => $stats, 'unfixed_why' => self::topWhy($unfixed)],
        )], null, false);
    }

    /**
     * Combines the statuses of images (or the raw data of one image) and applies the rules of spec 15.6.
     *
     * @param  non-empty-list<ResourceSecurity>  $parts
     * @param  array{checked: int, total: int}|null  $images
     */
    private static function resolve(array $parts, ?array $images, bool $isService): ResourceSecurity
    {
        $entries = [];
        $counts = array_fill_keys(self::COUNT_KEYS, 0);
        $reasons = [];
        $unfixed = [];
        $eol = null;
        $checkedAt = null;
        $unknownTime = false;
        foreach ($parts as $part) {
            $facts = $part->facts;
            foreach ($part->actions as $index => $action) {
                $entries[] = ['action' => $action, 'stats' => self::stats(data_get($facts, "action_stats.{$index}"))];
            }
            foreach (self::COUNT_KEYS as $key) {
                $counts[$key] += (int) data_get($facts, "counts.{$key}", 0);
            }
            $reasons = [...$reasons, ...array_filter((array) ($facts['reasons'] ?? []), 'is_string')];
            $unfixed = [...$unfixed, ...array_map(fn ($entry): array => ResourceSecurity::whyEntry((array) $entry), (array) ($facts['unfixed_why'] ?? []))];
            if ($part->eol !== null && ($eol === null || ($eol['fixed'] && ! $part->eol['fixed']))) {
                $eol = $part->eol;
            }
            if ($part->checkedAt === null) {
                $unknownTime = true;
            } elseif ($checkedAt === null || $part->checkedAt->lt($checkedAt)) {
                $checkedAt = $part->checkedAt;
            }
        }
        if ($unknownTime) {
            $checkedAt = null;
        }

        if ($isService) {
            $entries = self::mergeUpdates($entries);
        }
        $entries = self::sortEntries($entries);

        $actionStatus = match (true) {
            $counts['signal_fixed'] > 0 => SecurityStatusType::UpdateNow,
            $counts['relevant_fixed'] > 0 => SecurityStatusType::UpdateAvailable,
            $counts['signal_unfixed'] > 0 => SecurityStatusType::NoFix,
            default => SecurityStatusType::Ok,
        };

        $storedReasons = array_values(array_unique($reasons));
        $allReasons = $storedReasons;
        if ($checkedAt === null || $checkedAt->lt(now()->subDays(self::STALE_AFTER_DAYS))) {
            $allReasons[] = self::REASON_STALE;
        }
        if ($images !== null && $images['checked'] < $images['total']) {
            $allReasons[] = self::REASON_IMAGES_UNCHECKED;
        }
        $reason = collect(self::REASON_ORDER)->first(fn (string $code): bool => in_array($code, $allReasons, true));
        $status = $reason === null ? $actionStatus : SecurityStatusType::CheckNeeded;

        $primary = $entries[0]['stats'] ?? null;
        $why = [];
        $moreFixed = 0;
        if ($primary !== null && in_array($actionStatus, [SecurityStatusType::UpdateNow, SecurityStatusType::UpdateAvailable], true)) {
            $why = $primary['why'];
            $moreFixed = max(0, $primary['relevant'] - $primary['eol'] - count($why));
        } elseif ($actionStatus === SecurityStatusType::NoFix) {
            $why = self::topWhy($unfixed);
        }

        return new ResourceSecurity(
            status: $status,
            checkReason: $reason,
            checkContext: match ($reason) {
                self::REASON_IMAGES_UNCHECKED => $images,
                self::REASON_STALE => ['time' => $checkedAt?->toIso8601String()],
                default => [],
            },
            actions: array_map(fn (array $entry): SecurityAction => $entry['action'], $entries),
            why: $why,
            moreFixed: $moreFixed,
            exploitedWithoutFix: $counts['signal_unfixed'] > 0 && in_array($status, [SecurityStatusType::UpdateNow, SecurityStatusType::UpdateAvailable], true),
            problemCount: match ($actionStatus) {
                SecurityStatusType::UpdateNow => $counts['signal_fixed'],
                SecurityStatusType::UpdateAvailable => $counts['critical_fixed'] + $counts['high_fixed'],
                SecurityStatusType::NoFix => $counts['signal_unfixed'],
                default => 0,
            },
            exploitedCount: $counts['signal_fixed'] + $counts['signal_unfixed'],
            anyExploited: match ($actionStatus) {
                SecurityStatusType::UpdateNow => $counts['kev_fixed'] > 0,
                SecurityStatusType::NoFix => $counts['kev_unfixed'] > 0,
                default => false,
            },
            criticalCount: $counts['critical_fixed'],
            highCount: $counts['high_fixed'],
            fixedByPrimary: match ($actionStatus) {
                SecurityStatusType::UpdateNow => (int) ($primary['signal'] ?? 0),
                SecurityStatusType::UpdateAvailable => (int) ($primary['critical'] ?? 0) + (int) ($primary['high'] ?? 0),
                default => 0,
            },
            primaryCritical: (int) ($primary['critical'] ?? 0),
            primaryHigh: (int) ($primary['high'] ?? 0),
            eol: $eol,
            checkedAt: $checkedAt,
            facts: [
                'action_status' => $actionStatus->value,
                'reasons' => $storedReasons,
                'counts' => $counts,
                'action_stats' => array_map(fn (array $entry): array => $entry['stats'], $entries),
                'unfixed_why' => self::topWhy($unfixed),
            ],
            builderImage: $counts['signal_unfixed'] > 0 && $counts['builder_unfixed'] === $counts['signal_unfixed'],
        );
    }

    /**
     * Images of one service that run the same tag and update to the same tag get one action, also from different
     * repositories: "Update n8n and n8n runners to 2.10.4." The action lists every image in `parts`.
     *
     * @param  list<array{action: SecurityAction, stats: array<string, mixed>}>  $entries
     * @return list<array{action: SecurityAction, stats: array<string, mixed>}>
     */
    private static function mergeUpdates(array $entries): array
    {
        $groups = [];
        foreach ($entries as $index => $entry) {
            $key = self::updateKey($entry);
            $groups[$key ?? 'single:'.$index][] = $index;
        }

        $merged = [];
        foreach ($groups as $indexes) {
            $group = array_map(fn (int $index): array => $entries[$index], $indexes);
            $names = array_values(array_unique(array_map(fn (array $entry): string => (string) $entry['stats']['owner'], $group)));
            if (count($group) === 1 || count($names) < 2) {
                $merged = [...$merged, ...$group];

                continue;
            }

            $first = $group[0]['action'];
            $last = array_pop($names);
            $stats = self::emptyStats();
            foreach ($group as $entry) {
                foreach (['signal', 'kev', 'critical', 'high', 'relevant', 'eol'] as $key) {
                    $stats[$key] += $entry['stats'][$key];
                }
                $stats['why'] = [...$stats['why'], ...$entry['stats']['why']];
            }
            $stats['why'] = self::topWhy($stats['why']);

            $merged[] = [
                'action' => new SecurityAction(
                    type: SecurityActionType::UpdateImage,
                    sentence: 'Update '.implode(', ', $names).' and '.$last.' to '.$first->target.'.',
                    target: $first->target,
                    copy: null,
                    fixedIds: array_values(array_unique(array_merge(...array_map(fn (array $entry): array => $entry['action']->fixedIds, $group)))),
                    button: collect($group)->every(fn (array $entry): bool => $entry['action']->button),
                    image: $first->image,
                    ref: $first->ref,
                    parts: array_map(fn (array $entry): array => [
                        'owner_type' => (string) $entry['stats']['owner_type'],
                        'owner_id' => $entry['stats']['owner_id'],
                        'image' => $entry['action']->image,
                        'ref' => $entry['action']->ref,
                    ], $group),
                ),
                'stats' => $stats,
            ];
        }

        return $merged;
    }

    /**
     * Current tag and target tag of an update of a service image that can be merged, else null.
     *
     * @param  array{action: SecurityAction, stats: array<string, mixed>}  $entry
     */
    private static function updateKey(array $entry): ?string
    {
        $action = $entry['action'];
        if ($action->type !== SecurityActionType::UpdateImage || $action->sentence === SecurityActions::COOLIFY_TEXT
            || blank($action->target) || blank($action->image) || blank($entry['stats']['owner']) || blank($entry['stats']['owner_type'])) {
            return null;
        }

        try {
            $current = ImageRef::parse((string) $action->image);

            return $current->digest === null ? ($current->tag ?? 'latest').'|'.$action->target : null;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Most fixes with an exploit signal first, then most critical and high fixes (leaving an end-of-life OS counts as
     * one critical fix), then the order of the action types.
     *
     * @param  list<array{action: SecurityAction, stats: array<string, mixed>}>  $entries
     * @return list<array{action: SecurityAction, stats: array<string, mixed>}>
     */
    private static function sortEntries(array $entries): array
    {
        $order = array_flip(array_map(fn (SecurityActionType $type): string => $type->value, SecurityActionType::cases()));
        $keyed = array_map(fn (array $entry, int $index): array => [...$entry, 'index' => $index], $entries, array_keys($entries));
        usort($keyed, fn (array $a, array $b): int => [
            -$a['stats']['signal'],
            -($a['stats']['critical'] + $a['stats']['high']),
            $order[$a['action']->type->value],
            $a['index'],
        ] <=> [
            -$b['stats']['signal'],
            -($b['stats']['critical'] + $b['stats']['high']),
            $order[$b['action']->type->value],
            $b['index'],
        ]);

        return array_map(fn (array $entry): array => ['action' => $entry['action'], 'stats' => $entry['stats']], $keyed);
    }

    /**
     * What one action fixes: owner (name, morph type, key), relevant fixes with an exploit signal, `exploited` ones,
     * critical and high ones, all relevant ones, an end-of-life OS it leaves (counted in `critical` and `relevant`
     * too), and its why-list.
     *
     * @return array{owner: ?string, owner_type: ?string, owner_id: int|string|null, signal: int, kev: int, critical: int, high: int, relevant: int, eol: int, why: list<array<string, mixed>>}
     */
    private static function emptyStats(): array
    {
        return ['owner' => null, 'owner_type' => null, 'owner_id' => null, 'signal' => 0, 'kev' => 0, 'critical' => 0, 'high' => 0, 'relevant' => 0, 'eol' => 0, 'why' => []];
    }

    /**
     * @return array{owner: ?string, owner_type: ?string, owner_id: int|string|null, signal: int, kev: int, critical: int, high: int, relevant: int, eol: int, why: list<array<string, mixed>>}
     */
    private static function stats(mixed $data): array
    {
        $data = is_array($data) ? $data : [];
        $ownerId = $data['owner_id'] ?? null;

        return [
            'owner' => isset($data['owner']) ? (string) $data['owner'] : null,
            'owner_type' => isset($data['owner_type']) ? (string) $data['owner_type'] : null,
            'owner_id' => is_int($ownerId) || is_string($ownerId) ? $ownerId : null,
            'signal' => (int) ($data['signal'] ?? 0),
            'kev' => (int) ($data['kev'] ?? 0),
            'critical' => (int) ($data['critical'] ?? 0),
            'high' => (int) ($data['high'] ?? 0),
            'relevant' => (int) ($data['relevant'] ?? 0),
            'eol' => (int) ($data['eol'] ?? 0),
            'why' => array_map(fn ($entry): array => ResourceSecurity::whyEntry((array) $entry), (array) ($data['why'] ?? [])),
        ];
    }

    /**
     * @return array{title: string, display_id: string, link: ?string, score: ?float, severity: string, signal: ?string, package: string, version: string}
     */
    public static function whyFor(Finding $finding, ?string $signal): array
    {
        return [
            'title' => $finding->title,
            'display_id' => $finding->displayId,
            'link' => ResourceScans::advisoryUrl($finding->displayId),
            'score' => $finding->cvssScore,
            'severity' => strtolower($finding->severity),
            'signal' => $signal,
            'package' => $finding->package,
            'version' => $finding->version,
        ];
    }

    /**
     * `exploited`, then `likely`, then the highest score (no score last), then severity, at most $limit.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    public static function topWhy(array $entries, int $limit = self::WHY_LIMIT): array
    {
        $signalRank = [SecurityRules::EXPLOITED => 0, SecurityRules::LIKELY => 1];
        $severityRank = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        usort($entries, fn (array $a, array $b): int => [
            $signalRank[$a['signal'] ?? ''] ?? 2,
            -($a['score'] ?? -1.0),
            $severityRank[$a['severity']] ?? 4,
            $a['display_id'],
        ] <=> [
            $signalRank[$b['signal'] ?? ''] ?? 2,
            -($b['score'] ?? -1.0),
            $severityRank[$b['severity']] ?? 4,
            $b['display_id'],
        ]);

        return array_slice($entries, 0, $limit);
    }

    /**
     * The vulnerability database was more than DATABASE_MAX_AGE_DAYS old when the image was scanned. Unknown for rows
     * without the build time, which every completed scan records.
     */
    private static function databaseWasOld(ImageScan $scan): bool
    {
        $builtAt = $scan->grype_db_built_at;
        $scannedAt = $scan->scanned_at;

        return $builtAt !== null && $scannedAt !== null && $builtAt->lt($scannedAt->copy()->subDays(self::DATABASE_MAX_AGE_DAYS));
    }

    /**
     * The version check (images the user does not build) and the base check (Dockerfile apps with a base image) have
     * a result. `cannot_compare` and `no_better` count as checked, and so do the checks that only the user can unblock
     * (`isBlockedCheck()`): they have no result, and the card says why. A Dockerfile app also needs to know which
     * findings come from its current base image. Notifications pass `$blockedCounts = false`: they say that Coolify
     * could not check for a fixed version instead of "no fix yet".
     */
    public static function versionsChecked(ImageScan $scan, Model $owner, bool $blockedCounts = true): bool
    {
        $settled = fn (mixed $status, mixed $error): bool => in_array($status, self::CHECKED_STATUSES, true)
            || ($blockedCounts && self::isBlockedCheck($status, $error));

        $needsImageCheck = ! $owner instanceof Application
            || $owner->build_pack === 'dockerimage'
            || ($owner->build_pack === 'dockercompose' && $scan->builds_image === false);
        if ($needsImageCheck && ! $settled($scan->candidateStatus(), data_get($scan->candidates, 'error'))) {
            return false;
        }

        if ($owner instanceof Application && $owner->build_pack === 'dockerfile' && filled($scan->base_image_ref)) {
            $base = data_get($scan->candidates, 'base');

            return is_array($base)
                && ($base['image'] ?? null) === $scan->base_image_ref
                && $settled($base['status'] ?? null, $base['error'] ?? null)
                && is_int($base['attributable'] ?? null);
        }

        return true;
    }

    /**
     * A version or base check that Coolify cannot finish by itself: the registry needs a login (`auth_required`), or
     * the server reached the daily limit of checks (`failed` with `daily_limit`). Other failures and a busy lock
     * (`failed` with `lock_busy`) are tried again, so they stay check_needed.
     */
    public static function isBlockedCheck(mixed $status, mixed $error): bool
    {
        return $status === 'auth_required' || ($status === 'failed' && $error === 'daily_limit');
    }

    private static function ownerName(Model $owner): ?string
    {
        $name = $owner instanceof ServiceApplication || $owner instanceof ServiceDatabase
            ? ($owner->human_name ?: $owner->name)
            : $owner->getAttribute('name');

        return filled($name) ? (string) $name : null;
    }
}
