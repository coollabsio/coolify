<?php

namespace App\Services\ImageScan;

use App\Actions\ImageScan\RefreshCandidates;
use App\Actions\ImageScan\UpdateResourceImage;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\ImageScanStatus;
use App\Enums\SecurityActionType;
use App\Enums\SecurityStatusType;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ImageScan;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * What the main card of the Security page shows (spec 15.7, 15.11): the status of the resource, and one row per image
 * with its status badge, sentence, primary action with its button or copy text, why-list, provenance, the other
 * actions, and the running digest. Buttons are only offered when the user may run them; the Livewire actions authorize
 * again.
 *
 * @phpstan-type ButtonData array{label: string, title: string, submit: string, actions: list<string>, warning: string}
 * @phpstan-type ActionData array{key: string, type: string, sentence: string, copy: ?string, button: ?ButtonData}
 * @phpstan-type WhyData array{title: string, display_id: string, link: ?string, score: string, signal: ?string, package: string, version: string}
 * @phpstan-type ImageRow array{key: string, name: ?string, ref: string, status: string, badge: ?string, badgeType: string, open: bool, problem: bool, sentence: string, attackNote: ?string, advice: ?string, lines: list<string>, primary: ?ActionData, mergedWith: ?string, why: list<WhyData>, whyMore: string, provenance: ?string, others: list<ActionData>, moreActions: int, versionNotes: list<array{text: string, link: ?string}>, digest: ?string, digestFull: ?string, known: string, filter: ?string}
 */
final class SecurityCard
{
    public const OTHER_ACTIONS_LIMIT = 5;

    /** Image rows, worst first (the order of the team page). */
    private const ROW_ORDER = ['update_now', 'update_available', 'no_fix', 'check_needed', 'ok', 'not_checked'];

    private const PROBLEM_STATUSES = ['update_now', 'update_available', 'no_fix', 'check_needed'];

    private const DATABASE_NAMES = [
        'postgresql' => 'PostgreSQL',
        'redis' => 'Redis',
        'mongodb' => 'MongoDB',
        'mysql' => 'MySQL',
        'mariadb' => 'MariaDB',
        'keydb' => 'KeyDB',
        'dragonfly' => 'Dragonfly',
        'clickhouse' => 'ClickHouse',
    ];

    /**
     * @param  Collection<int, Model>  $owners  The applications and databases of a service, else the resource.
     * @param  Collection<int, ImageScan>  $scans  The current scans of the owners, with their `resource` set.
     * @param  array<string, int>  $findingCounts  The findings that are not ignored, by scan UUID.
     * @return array{status: string, badge: ?string, badgeType: string, header: ?string, needsUpdate: int, total: int, single: bool, rows: list<ImageRow>}
     */
    public static function for(Model $resource, ResourceSecurity $security, Collection $owners, Collection $scans, User $user, array $findingCounts = []): array
    {
        $status = $security->status;
        $rows = self::images($resource, $security, $owners, $scans, $user, $findingCounts);
        $total = count($rows) + ($resource instanceof Application ? ResourceScans::uncheckedComposeImages($resource, $scans) : 0);
        $needsUpdate = count(array_filter($rows, fn (array $row): bool => in_array($row['status'], ['update_now', 'update_available'], true)));

        return [
            'status' => $status->value,
            'badge' => SecuritySentences::badge($status),
            'badgeType' => self::badgeType($status),
            'header' => $total > 1 ? SecuritySentences::imagesNeedingUpdate($needsUpdate, $total) : null,
            'needsUpdate' => $needsUpdate,
            'total' => $total,
            'single' => $total === 1,
            'rows' => $rows,
        ];
    }

    /**
     * One row per image the status counts: each current scan (of a service, of the applications and databases Coolify
     * scans), and the scanned owners of a service without a current scan. Worst first; the first row with a problem
     * is open, and a single image is always open. A service update that merges several images (spec 15.5) has its
     * button on the first row of the group; the other rows say which row updates them.
     *
     * @param  Collection<int, Model>  $owners
     * @param  Collection<int, ImageScan>  $scans
     * @param  array<string, int>  $findingCounts
     * @return list<ImageRow>
     */
    public static function images(Model $resource, ResourceSecurity $security, Collection $owners, Collection $scans, User $user, array $findingCounts = []): array
    {
        $isService = $resource instanceof Service;
        $statusOwners = $isService ? $owners->filter(fn (Model $owner): bool => ResourceScans::isScanTarget($owner))->values() : $owners;
        $isStatusOwner = fn (?Model $owner): bool => $owner instanceof Model && $statusOwners->contains(fn (Model $candidate): bool => $candidate->is($owner));

        $digests = [];
        foreach ($scans as $scan) {
            $digests[self::ownerKey($scan->resource)] ??= SecuritySentences::shortDigest($scan->repo_digest ?: $scan->image_id);
        }

        $entries = [];
        foreach ($scans as $scan) {
            if ($isService && ! $isStatusOwner($scan->resource)) {
                continue;
            }
            $entries[] = ['scan' => $scan, 'owner' => $scan->resource, 'security' => SecurityStatus::forScan($scan)];
        }
        foreach ($statusOwners as $owner) {
            if (! $scans->contains(fn (ImageScan $scan): bool => $scan->resource instanceof Model && $scan->resource->is($owner))) {
                $entries[] = ['scan' => null, 'owner' => $owner, 'security' => new ResourceSecurity(SecurityStatusType::NotChecked, SecurityStatus::NOT_SCANNED)];
            }
        }

        $rank = array_flip(self::ROW_ORDER);
        usort($entries, fn (array $a, array $b): int => [$rank[$a['security']->status->value], self::rowName($a, $isService), self::rowRef($a)]
            <=> [$rank[$b['security']->status->value], self::rowName($b, $isService), self::rowRef($b)]);

        $merged = array_values(array_filter($security->actions, fn (SecurityAction $action): bool => $action->parts !== []));
        $mergedStats = [];
        foreach ($security->actions as $index => $action) {
            if ($action->parts !== []) {
                $mergedStats[self::key($action)] = (array) data_get($security->facts, "action_stats.{$index}", []);
            }
        }
        $groupFirst = [];
        $single = count($entries) === 1;
        $opened = false;
        $rows = [];
        foreach ($entries as $entry) {
            /** @var ResourceSecurity $imageSecurity */
            $imageSecurity = $entry['security'];
            /** @var ?ImageScan $scan */
            $scan = $entry['scan'];
            $owner = $entry['owner'];
            $status = $imageSecurity->status;
            $ref = self::rowRef($entry);
            $name = $isService ? self::ownerName($owner) : null;
            $showsActions = in_array($status, [SecurityStatusType::UpdateNow, SecurityStatusType::UpdateAvailable], true);

            $actions = [];
            if ($showsActions) {
                foreach ($imageSecurity->actions as $index => $action) {
                    $actions[] = self::action($resource, $action, (array) data_get($imageSecurity->facts, "action_stats.{$index}", []), $owners, $user, $digests);
                }
            }

            $primaryAction = $showsActions ? $imageSecurity->primary() : null;
            $primary = $actions[0] ?? null;
            $mergedWith = null;
            $group = $primaryAction === null ? null : self::mergedGroup($merged, $primaryAction, $owner);
            if ($group !== null) {
                $groupKey = self::key($group);
                if (isset($groupFirst[$groupKey])) {
                    $mergedWith = $groupFirst[$groupKey];
                    $primary = null;
                } else {
                    $groupFirst[$groupKey] = (string) ($name ?? $ref);
                    $primary = self::action($resource, $group, $mergedStats[$groupKey] ?? [], $owners, $user, $digests);
                }
            }

            $isProblem = in_array($status->value, self::PROBLEM_STATUSES, true);
            $open = $single || ($isProblem && ! $opened);
            $opened = $opened || $open;
            $others = array_slice($actions, 1);
            $counts = $scan === null ? null : ($findingCounts[(string) $scan->uuid] ?? null);
            $digestFull = $scan === null ? null : ($scan->repo_digest ?: $scan->image_id);

            $rows[] = [
                'key' => $scan !== null ? 'scan-'.$scan->uuid : 'owner-'.$owner->getAttribute('uuid'),
                'name' => $name,
                'ref' => $ref,
                'status' => $status->value,
                'badge' => SecuritySentences::badge($status),
                'badgeType' => self::badgeType($status),
                'open' => $open,
                'problem' => $isProblem,
                'sentence' => SecuritySentences::imageSentence($imageSecurity, $ref),
                'attackNote' => SecuritySentences::attackNote($imageSecurity),
                'advice' => SecuritySentences::noFixAdvice($imageSecurity),
                'lines' => [...SecuritySentences::lines($imageSecurity), ...self::eolLine($imageSecurity)],
                'primary' => $primary,
                'mergedWith' => $mergedWith,
                'why' => in_array($status, [SecurityStatusType::UpdateNow, SecurityStatusType::UpdateAvailable, SecurityStatusType::NoFix], true)
                    ? array_map(fn (array $why): array => self::whyRow($why), $imageSecurity->why)
                    : [],
                'whyMore' => $showsActions ? SecuritySentences::whyMore($imageSecurity->moreFixed) : '',
                'provenance' => $scan === null || ! in_array($status, [SecurityStatusType::UpdateNow, SecurityStatusType::UpdateAvailable, SecurityStatusType::NoFix], true)
                    ? null
                    : SecuritySentences::provenance($scan, $primaryAction, $imageSecurity, self::provenanceCounts($scan, $primaryAction, $imageSecurity)),
                'others' => array_slice($others, 0, self::OTHER_ACTIONS_LIMIT),
                'moreActions' => max(0, count($others) - self::OTHER_ACTIONS_LIMIT),
                'versionNotes' => $scan === null ? [] : self::versionCheckNotes(collect([$scan]), $user),
                'digest' => SecuritySentences::shortDigest($digestFull),
                'digestFull' => $digestFull,
                'known' => $counts === null ? '' : SecuritySentences::knownIssues($counts),
                'filter' => $single ? null : $ref,
            ];
        }

        return $rows;
    }

    private static function badgeType(SecurityStatusType $status): string
    {
        return match ($status) {
            SecurityStatusType::UpdateNow => 'error',
            SecurityStatusType::UpdateAvailable => 'warning',
            default => 'neutral',
        };
    }

    /**
     * @param  array<string, mixed>  $why
     * @return WhyData
     */
    private static function whyRow(array $why): array
    {
        return [
            'title' => ScannerText::tidyTitle((string) $why['title']),
            'display_id' => (string) $why['display_id'],
            'link' => $why['link'],
            'score' => $why['score'] === null ? 'No score' : number_format((float) $why['score'], 1).' '.ucfirst((string) $why['severity']),
            'signal' => match ($why['signal']) {
                SecurityRules::EXPLOITED => 'Used by attackers',
                SecurityRules::LIKELY => 'Likely to be exploited',
                default => null,
            },
            'package' => (string) ($why['package'] ?? ''),
            'version' => (string) ($why['version'] ?? ''),
        ];
    }

    /**
     * The merged service update (from the status of the resource) that contains this image's update.
     *
     * @param  list<SecurityAction>  $merged
     */
    private static function mergedGroup(array $merged, SecurityAction $action, Model $owner): ?SecurityAction
    {
        if ($action->type !== SecurityActionType::UpdateImage) {
            return null;
        }

        foreach ($merged as $group) {
            foreach ($group->parts as $part) {
                if ($part['owner_type'] === $owner->getMorphClass() && (string) $part['owner_id'] === (string) $owner->getKey() && $part['ref'] === $action->ref) {
                    return $group;
                }
            }
        }

        return null;
    }

    /**
     * What the provenance line counts: the relevant findings the action fixes, the less serious ones the candidate
     * adds (from the stored candidate scan; unknown for candidates checked before Coolify stored the counts), and the
     * first fixed version of a package upgrade.
     *
     * @return array{fixes?: ?int, adds?: ?int, fixed_in?: ?string}
     */
    private static function provenanceCounts(ImageScan $scan, ?SecurityAction $action, ResourceSecurity $security): array
    {
        if ($action === null) {
            return [];
        }

        if (in_array($action->type, [SecurityActionType::UpgradePackage, SecurityActionType::UpgradeInDockerfile], true)) {
            $ids = array_flip(array_map('mb_strtolower', $action->fixedIds));
            $fixedIn = null;
            foreach (ResourceScans::findings($scan) as $finding) {
                if ($finding->package === $action->target && isset($ids[mb_strtolower($finding->id)]) && filled($finding->fixedIn)
                    && ($fixedIn === null || version_compare(ltrim((string) $finding->fixedIn, 'vV'), ltrim($fixedIn, 'vV'), '>'))) {
                    $fixedIn = (string) $finding->fixedIn;
                }
            }

            return ['fixed_in' => $fixedIn];
        }

        if (! in_array($action->type, [SecurityActionType::UpdateImage, SecurityActionType::PullLatest], true)) {
            return [];
        }

        $kind = $action->type === SecurityActionType::PullLatest ? 'same_tag' : 'line';
        $entry = collect($scan->updateCandidates())->first(fn (array $entry): bool => ($entry['kind'] ?? null) === $kind && ($entry['ref'] ?? null) === $action->ref) ?? [];
        $stats = (array) data_get($security->facts, 'action_stats.0', []);
        // The relevant findings the action fixes now, so the line agrees with the sentence after ignores changed.
        $fixes = max(0, (int) ($stats['relevant'] ?? 0) - (int) ($stats['eol'] ?? 0));
        if (! is_array($entry['left'] ?? null)) {
            return ['fixes' => $fixes, 'adds' => null];
        }

        $owner = $scan->resource;
        $ignores = IgnoreMatcher::forResource($owner);
        $current = [];
        foreach (ResourceScans::findings($scan) as $finding) {
            if (in_array(strtolower($finding->severity), ['critical', 'high', 'medium'], true) && SecurityRules::isRelevant($finding, $ignores)) {
                $current[mb_strtolower($finding->id)] = true;
            }
        }

        return ['fixes' => $fixes, 'adds' => max(0, (int) array_sum($entry['left']) - (count($current) - $fixes))];
    }

    /**
     * @param  array{scan: ?ImageScan, owner: Model, security: ResourceSecurity}  $entry
     */
    private static function rowRef(array $entry): string
    {
        return $entry['scan'] !== null ? (string) $entry['scan']->image_ref : trim((string) $entry['owner']->getAttribute('image'));
    }

    /**
     * @param  array{scan: ?ImageScan, owner: Model, security: ResourceSecurity}  $entry
     */
    private static function rowName(array $entry, bool $isService): string
    {
        return $isService ? mb_strtolower(self::ownerName($entry['owner'])) : '';
    }

    private static function ownerKey(?Model $owner): string
    {
        return $owner === null ? '' : $owner->getMorphClass().':'.$owner->getKey();
    }

    /**
     * Lines for version and base checks that only the user can unblock (`SecurityStatus::isBlockedCheck()`): they
     * count as checked, so the card says why no newer version is offered. A registry login links to the server's
     * registry logins when the user may update the server.
     *
     * @param  Collection<int, ImageScan>  $scans  The current scans of the page.
     * @return list<array{text: string, link: ?string}>
     */
    public static function versionCheckNotes(Collection $scans, User $user): array
    {
        $notes = [];
        foreach ($scans as $scan) {
            $base = data_get($scan->candidates, 'base');
            $checks = [false => [$scan->candidateStatus(), data_get($scan->candidates, 'error')]];
            if (filled($scan->base_image_ref) && is_array($base) && ($base['image'] ?? null) === $scan->base_image_ref) {
                $checks[true] = [$base['status'] ?? null, $base['error'] ?? null];
            }

            foreach ($checks as $isBase => [$status, $error]) {
                if (! SecurityStatus::isBlockedCheck($status, $error)) {
                    continue;
                }

                $text = RefreshCandidates::message($scan, (bool) $isBase);
                if ($text === null || isset($notes[$text])) {
                    continue;
                }

                $server = $scan->server;
                $notes[$text] = [
                    'text' => $text,
                    'link' => $status === 'auth_required' && $server instanceof Server && $user->can('update', $server)
                        ? route('server.registries', ['server_uuid' => $server->uuid])
                        : null,
                ];
            }
        }

        return array_values($notes);
    }

    /**
     * A stable key of an action: its type, the image and candidate, and every part of a merged update.
     */
    public static function key(SecurityAction $action): string
    {
        return substr(sha1(json_encode([
            $action->type->value,
            $action->image,
            $action->ref,
            $action->target,
            array_map(fn (array $part): array => [(string) $part['owner_type'], (string) $part['owner_id'], (string) $part['ref']], $action->parts),
        ]) ?: ''), 0, 16);
    }

    /**
     * The deployment whose image runs now: the latest finished deployment that is not a preview.
     */
    public static function runningDeployment(Application $application): ?ApplicationDeploymentQueue
    {
        return ApplicationDeploymentQueue::query()
            ->where('application_id', $application->id)
            ->where('pull_request_id', 0)
            ->where('status', ApplicationDeploymentStatus::FINISHED->value)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The action the deploy gate popover shows for a blocked deployment: the action stored with the gate result, else
     * the primary action of the blocked image (of a Compose app, the image whose primary action fixes the most
     * findings with an exploit signal), computed once per version of its scans. Without a scan of the deployment, the
     * primary action of the main card.
     *
     * @return array{sentence: string, button: bool}|null
     */
    public static function gateAction(Application $application, ApplicationDeploymentQueue $deployment): ?array
    {
        $stored = data_get($deployment->scan_gate_result, 'action');
        if (is_array($stored) && filled($stored['sentence'] ?? null)) {
            return ['sentence' => (string) $stored['sentence'], 'button' => (bool) ($stored['button'] ?? false)];
        }

        $scans = fn () => ImageScan::query()
            ->where('resource_type', $application->getMorphClass())
            ->where('resource_id', $application->id)
            ->where('deployment_queue_id', $deployment->id)
            ->where('status', ImageScanStatus::Completed);
        // The version changes with the scan, its version and base checks, and its stored status (ignores).
        $versions = $scans()->orderBy('id')->get(['id', 'updated_at', 'candidates', 'summary'])
            ->map(fn (ImageScan $scan): array => [$scan->id, (string) $scan->updated_at, $scan->candidates, $scan->summary]);
        if ($versions->isEmpty()) {
            return self::actionSummary(SecurityStatus::stored($application)->primary());
        }

        return Cache::remember(
            "image-scan:gate-action:{$deployment->id}:".md5((string) json_encode($versions)),
            now()->addDay(),
            function () use ($scans, $application): array {
                $best = null;
                $bestRank = null;
                foreach ($scans()->get() as $scan) {
                    $action = self::rankedAction($scan->setRelation('resource', $application));
                    if ($action !== null && ($bestRank === null || $action['rank'] > $bestRank)) {
                        [$best, $bestRank] = [$action['action'], $action['rank']];
                    }
                }

                return ['action' => self::actionSummary($best)];
            },
        )['action'];
    }

    /**
     * The primary action of a blocked image, stored with the gate result so the popover does not compute it again.
     *
     * @return array{sentence: string, button: bool}|null
     */
    public static function blockedImageAction(ImageScan $scan): ?array
    {
        return self::actionSummary(self::rankedAction($scan)['action'] ?? null);
    }

    /**
     * @return array{action: SecurityAction, rank: array{0: int, 1: int}}|null
     */
    private static function rankedAction(ImageScan $scan): ?array
    {
        $security = SecurityStatus::forScan($scan);
        $primary = $security->primary();
        if ($primary === null) {
            return null;
        }
        $stats = (array) data_get($security->facts, 'action_stats.0', []);

        return ['action' => $primary, 'rank' => [(int) ($stats['signal'] ?? 0), (int) ($stats['critical'] ?? 0) + (int) ($stats['high'] ?? 0)]];
    }

    /**
     * @return array{sentence: string, button: bool}|null
     */
    private static function actionSummary(?SecurityAction $action): ?array
    {
        return $action === null ? null : ['sentence' => $action->sentence, 'button' => $action->button];
    }

    /**
     * A Git app can be rebuilt only from a known commit: "HEAD" or no commit would build whatever the branch has now.
     */
    public static function knowsRunningCommit(Application $application, ApplicationDeploymentQueue $deployment): bool
    {
        $commit = trim((string) $deployment->commit);

        return ! $application->git_based() || ($commit !== '' && strtoupper($commit) !== 'HEAD');
    }

    public static function shortCommit(?string $commit): string
    {
        $commit = trim((string) $commit);

        return $commit === '' ? 'HEAD' : mb_substr($commit, 0, 7);
    }

    /**
     * The end-of-life OS, when the sentence does not say it already.
     *
     * @return list<string>
     */
    private static function eolLine(ResourceSecurity $security): array
    {
        if ($security->eol === null || $security->eol['name'] === '') {
            return [];
        }

        $said = in_array($security->status, [SecurityStatusType::UpdateNow, SecurityStatusType::UpdateAvailable], true)
            && (int) data_get($security->facts, 'action_stats.0.eol', 0) > 0;
        if ($said) {
            return [];
        }

        return [trim('This image uses '.ucfirst($security->eol['name']).' '.$security->eol['version']).', which no longer gets security fixes.'];
    }

    /**
     * @param  array<string, mixed>  $stats
     * @param  Collection<int, Model>  $owners
     * @param  array<string, ?string>  $digests  The short digest of the running image of each owner (morph type and key).
     * @return ActionData
     */
    private static function action(Model $resource, SecurityAction $action, array $stats, Collection $owners, User $user, array $digests = []): array
    {
        return [
            'key' => self::key($action),
            'type' => $action->type->value,
            'sentence' => $action->sentence,
            'copy' => $action->copy,
            'button' => $action->button && (int) $resource->getKey() !== 0 ? self::button($resource, $action, $stats, $owners, $user, $digests) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $stats
     * @param  Collection<int, Model>  $owners
     * @param  array<string, ?string>  $digests
     * @return ButtonData|null
     */
    private static function button(Model $resource, SecurityAction $action, array $stats, Collection $owners, User $user, array $digests): ?array
    {
        if ($action->type === SecurityActionType::Rebuild) {
            if (! $resource instanceof Application || ! $user->can('deploy', $resource)) {
                return null;
            }
            $deployment = self::runningDeployment($resource);
            if ($deployment === null || ! self::knowsRunningCommit($resource, $deployment)) {
                return null;
            }
            $commit = self::shortCommit($deployment->commit);

            return [
                'label' => 'Rebuild',
                'title' => 'Rebuild without cache?',
                'submit' => 'rebuild',
                'actions' => ["Rebuild commit {$commit} without cache.", 'Redeploy the app.'],
                'warning' => "Coolify rebuilds commit {$commit} without cache and redeploys it.",
            ];
        }

        if (! in_array($action->type, [SecurityActionType::UpdateImage, SecurityActionType::PullLatest], true) || ! $user->can('update', $resource)) {
            return null;
        }

        if ($action->parts !== []) {
            return self::mergedButton($resource, $action, $owners, $digests);
        }

        $image = (string) $action->image;
        $ref = (string) $action->ref;
        if ($image === '' || $ref === '') {
            return null;
        }
        $owner = $resource instanceof Service
            ? $owners->first(fn (Model $owner): bool => $owner->getMorphClass() === ($stats['owner_type'] ?? null) && (string) $owner->getKey() === (string) ($stats['owner_id'] ?? ''))
            : $resource;
        if (! $owner instanceof Model) {
            return null;
        }
        $ownerUuid = $resource instanceof Service ? (string) $owner->getAttribute('uuid') : '';
        $submit = 'updateImage('.self::quote($ref).', '.self::quote($ownerUuid).')';
        $restart = self::restartLine($resource, $owners);

        if ($action->type === SecurityActionType::PullLatest) {
            return [
                'label' => 'Pull latest and restart',
                'title' => "Pull the newest build of {$image}?",
                'submit' => $submit,
                'actions' => ["Pull the newest build of {$image}.", $restart],
                'warning' => "Coolify pulls the newest build of {$image} and restarts.",
            ];
        }

        $tag = (string) $action->target;
        $changes = $resource instanceof Service
            ? UpdateResourceImage::subResourcesUsing($resource, $image)
                ->map(fn (Model $subResource): string => self::ownerName($subResource).': '.self::change($image, $digests[self::ownerKey($subResource)] ?? null, $ref))
                ->all()
            : [self::change($image, $digests[self::ownerKey($resource)] ?? null, $ref)];

        return [
            'label' => "Update to {$tag}",
            'title' => "Update to {$tag}?",
            'submit' => $submit,
            'actions' => [...$changes, $restart],
            'warning' => self::updateWarning($resource, $image, $ref),
        ];
    }

    /**
     * One confirmation for every image of a merged service update.
     *
     * @param  Collection<int, Model>  $owners
     * @param  array<string, ?string>  $digests
     * @return ButtonData|null
     */
    private static function mergedButton(Model $resource, SecurityAction $action, Collection $owners, array $digests): ?array
    {
        if (! $resource instanceof Service) {
            return null;
        }

        $changes = [];
        foreach ($action->parts as $part) {
            $image = (string) $part['image'];
            $ref = (string) $part['ref'];
            if ($image === '' || $ref === '') {
                return null;
            }
            foreach (UpdateResourceImage::subResourcesUsing($resource, $image) as $subResource) {
                $changes[self::ownerKey($subResource)] = self::ownerName($subResource).': '.self::change($image, $digests[self::ownerKey($subResource)] ?? null, $ref);
            }
        }
        if ($changes === []) {
            return null;
        }

        $tag = (string) $action->target;

        return [
            'label' => "Update to {$tag}",
            'title' => "Update to {$tag}?",
            'submit' => 'updateImages('.self::quote(self::key($action)).')',
            'actions' => [...array_values($changes), self::restartLine($resource, $owners)],
            'warning' => self::updateWarning($resource, '', ''),
        ];
    }

    private static function updateWarning(Model $resource, string $image, string $ref): string
    {
        if ($resource instanceof Service) {
            return 'One-click services pin versions that work together. Check the service template before you update.';
        }
        if ($resource instanceof Application) {
            return 'Coolify redeploys the app with the new image.';
        }

        $fromTag = self::tag($image);
        $toTag = self::tag($ref);
        $name = self::DATABASE_NAMES[array_search($resource::class, STANDALONE_DATABASE_MODELS, true) ?: ''] ?? null;
        if ($name !== null && $fromTag !== null && $toTag !== null && self::major($fromTag) !== null && self::major($fromTag) === self::major($toTag)) {
            return SecuritySentences::databaseUpdateConfirmation($name, $fromTag, $toTag);
        }

        return 'Check the release notes. Some updates need a data migration.';
    }

    /**
     * What restarts. A service restarts every container, so the line names its images.
     *
     * @param  Collection<int, Model>  $owners
     */
    private static function restartLine(Model $resource, Collection $owners): string
    {
        if ($resource instanceof Service) {
            $names = $owners->map(fn (Model $owner): string => self::ownerName($owner))->filter()->values()->all();

            return $names === []
                ? 'Pull the images and restart the service.'
                : 'Pull the images and restart the service: '.implode(', ', $names).'.';
        }

        return $resource instanceof Application ? 'Redeploy the app.' : 'Restart the database.';
    }

    /**
     * "postgres:15.0 3f1a2b4c5d6e → postgres:15.13": the running image, its short digest, and the new image.
     */
    private static function change(string $image, ?string $digest, string $ref): string
    {
        return trim($image.' '.($digest ?? '')).' → '.$ref;
    }

    private static function ownerName(Model $owner): string
    {
        return (string) ($owner->getAttribute('human_name') ?: $owner->getAttribute('name'));
    }

    private static function tag(string $ref): ?string
    {
        try {
            return ImageRef::parse($ref)->tag;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private static function major(string $tag): ?string
    {
        return preg_match('/^v?(\d+)/i', $tag, $matches) === 1 ? $matches[1] : null;
    }

    /**
     * A single-quoted argument for the confirmation modal's submit action.
     */
    private static function quote(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }
}
