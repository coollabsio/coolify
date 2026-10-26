<?php

namespace App\Services\ImageScan;

use App\Actions\ImageScan\UpdateResourceImage;
use App\Enums\ImageScanFixState;
use App\Enums\ImageScanStatus;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ImageScan;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\Team;
use App\Notifications\ImageScan\KnownExploitedFound;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * What the image vulnerability notifications have told a team already (spec 8).
 *
 * `image_scans.notified_ids` holds the IDs of the fixable-now findings at the alert level that a notification (or the
 * baseline) covered. A finding is new when none of its IDs and aliases is in the list. IDs of findings that are no
 * longer in the scan are dropped, so a vulnerability that comes back notifies again. A scan with `is_baseline` or
 * without a list is a baseline: its findings are recorded without a notification. The better candidates a digest
 * named are kept in `image_scans.notified_candidates`.
 */
final class ScanNotifications
{
    public const SEVERITIES = ['critical', 'high', 'medium', 'low', 'unknown'];

    /** The candidates a resource remembers as named; older ones are dropped. */
    public const MAX_NOTIFIED_CANDIDATES = 50;

    /**
     * After a scan stored its results: records a baseline, drops the IDs that are no longer found, and alerts the
     * team right away for a new known exploited finding that is fixable now. Preview scans never notify.
     *
     * A new image row starts from the union of what the current scans of the resource before this deployment had
     * notified. Without such scans (the first deployment, also each image of a first Compose deployment) the scan is
     * a baseline.
     */
    public static function afterScan(ImageScan $scan): void
    {
        if (! $scan->exists || $scan->status !== ImageScanStatus::Completed) {
            return;
        }

        $owner = $scan->resource;
        if (! $owner instanceof Model || self::isPreview($scan)) {
            return;
        }

        $fixable = self::fixableNow($scan, $owner);
        $ids = $scan->notified_ids;
        $candidates = $scan->notified_candidates;
        $baseline = (bool) $scan->is_baseline;

        if (! $baseline && $ids === null) {
            $previous = self::previousCurrentScans($scan)->filter(fn (ImageScan $row): bool => is_array($row->notified_ids));
            $baseline = $previous->isEmpty();
            if (! $baseline) {
                $ids = $previous->flatMap(fn (ImageScan $row): array => $row->notified_ids)->unique()->values()->all();
                $candidates ??= $previous->contains(fn (ImageScan $row): bool => is_array($row->notified_candidates))
                    ? self::capCandidates($previous->flatMap(fn (ImageScan $row): array => (array) $row->notified_candidates)->all())
                    : null;
            }
        }

        if ($baseline) {
            // Candidates of a baseline arrive later: without a list, the next digest records them without a notification.
            $scan->forceFill(['notified_ids' => self::ids($fixable), 'notified_candidates' => $candidates]);
            if ($scan->isDirty()) {
                $scan->saveQuietly();
            }

            return;
        }

        $ids = self::present($scan, $ids);
        $notified = self::idSet($ids);
        $known = array_values(array_filter($fixable, fn (Finding $finding): bool => $finding->kev && ! self::isIn($finding, $notified)));
        $scan->forceFill([
            'notified_ids' => array_values(array_unique([...$ids, ...self::ids($known)])),
            'notified_candidates' => $candidates,
        ]);
        if ($scan->isDirty()) {
            $scan->saveQuietly();
        }

        $team = method_exists($owner, 'team') ? $owner->team() : null;
        if ($known !== [] && $team instanceof Team) {
            $team->notify(new KnownExploitedFound($scan, count($known), self::ids($known)));
        }
    }

    /**
     * Fixable now at the alert level and not ignored: the findings of the counts and the gate.
     *
     * @return list<Finding>
     */
    public static function fixableNow(ImageScan $scan, Model $owner, ?IgnoreMatcher $ignores = null): array
    {
        $scan->setRelation('resource', $owner);
        $classify = ImageScanGate::classifierFor($scan);
        $ignores ??= IgnoreMatcher::forResource($owner);
        $level = ImageScanPolicyResolver::for($owner)->alertLevel;

        return array_values(array_filter(
            ResourceScans::findings($scan),
            fn (Finding $finding): bool => $level->includes($finding->severity)
                && $ignores->matches($finding) === null
                && $classify($finding) === ImageScanFixState::FixableNow,
        ));
    }

    /**
     * The digest records what a baseline sees without a notification: the first scan of a resource until its next
     * rescan, and a scan without notified IDs or candidates yet (each image of a first deployment).
     */
    public static function isBaseline(ImageScan $scan): bool
    {
        return (bool) $scan->is_baseline || $scan->notified_ids === null || $scan->notified_candidates === null;
    }

    /**
     * What a digest reports for one current scan, and what it records after sending (see `applyMark()`). A baseline
     * reports nothing and records what it sees.
     *
     * @return array{new: list<Finding>, fix: ?string, newCandidates: list<string>, mark: array{id: int, read: list<string>, ids: list<string>, candidates: list<string>}}
     */
    public static function digestPlan(ImageScan $scan, Model $owner): array
    {
        $fixable = self::fixableNow($scan, $owner);
        $candidates = self::betterCandidates($scan, $owner);
        $baseline = self::isBaseline($scan);

        $ids = $baseline ? [] : self::present($scan, (array) $scan->notified_ids);
        $notified = self::idSet($ids);
        $new = array_values(array_filter($fixable, fn (Finding $finding): bool => ! self::isIn($finding, $notified)));
        $namedCandidates = array_flip(array_map('strval', (array) $scan->notified_candidates));

        return [
            'new' => $baseline ? [] : $new,
            'fix' => array_values($candidates)[0] ?? null,
            'newCandidates' => $baseline ? [] : array_values(array_diff_key($candidates, $namedCandidates)),
            'mark' => [
                'id' => (int) $scan->getKey(),
                'read' => array_values(array_map('strval', (array) $scan->notified_ids)),
                'ids' => array_values(array_unique([...$ids, ...self::ids($new)])),
                'candidates' => array_map('strval', array_keys($candidates)),
            ],
        ];
    }

    /**
     * Records what a digest covered. Reads the row again, so IDs that an alert added meanwhile are kept, and writes
     * only the two notification columns.
     *
     * @param  array{id: int, read: list<string>, ids: list<string>, candidates: list<string>}  $mark
     */
    public static function applyMark(array $mark): void
    {
        $row = ImageScan::query()->select(['id', 'notified_ids', 'notified_candidates'])->find($mark['id']);
        if (! $row instanceof ImageScan) {
            return;
        }

        $addedMeanwhile = array_diff(array_map('strval', (array) $row->notified_ids), $mark['read']);

        ImageScan::query()->whereKey($mark['id'])->update([
            'notified_ids' => json_encode(array_values(array_unique([...$mark['ids'], ...$addedMeanwhile]))),
            'notified_candidates' => json_encode(self::capCandidates([...(array) $row->notified_candidates, ...$mark['candidates']])),
        ]);
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
     * The current scans of the resource before the deployment of this scan (see `ResourceScans::currentFor()`):
     * the latest other non-preview scan, with the other images of its deployment.
     *
     * @return Collection<int, ImageScan>
     */
    private static function previousCurrentScans(ImageScan $scan): Collection
    {
        $query = fn (): Builder => ImageScan::query()
            ->select(['id', 'deployment_queue_id', 'scanned_at', 'notified_ids', 'notified_candidates'])
            ->where('resource_type', $scan->resource_type)
            ->where('resource_id', $scan->resource_id)
            ->whereKeyNot($scan->getKey())
            ->where('status', ImageScanStatus::Completed)
            ->where(fn (Builder $query) => $query
                ->whereNull('deployment_queue_id')
                ->orWhereHas('deploymentQueue', fn (Builder $query) => $query->where('pull_request_id', 0)))
            ->when($scan->deployment_queue_id !== null, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereNull('deployment_queue_id')
                ->orWhere('deployment_queue_id', '!=', $scan->deployment_queue_id)))
            ->orderByDesc('scanned_at')
            ->orderByDesc('id');

        $latest = $query()->first();
        if (! $latest instanceof ImageScan) {
            return collect();
        }

        return $latest->deployment_queue_id === null
            ? collect([$latest])
            : $query()->where('deployment_queue_id', $latest->deployment_queue_id)->get();
    }

    /**
     * A scan of a pull request preview deployment.
     */
    private static function isPreview(ImageScan $scan): bool
    {
        return $scan->deployment_queue_id !== null
            && (int) ApplicationDeploymentQueue::query()->withoutGlobalScopes()->whereKey($scan->deployment_queue_id)->value('pull_request_id') !== 0;
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    private static function capCandidates(array $keys): array
    {
        return array_slice(array_values(array_unique(array_map('strval', $keys))), -self::MAX_NOTIFIED_CANDIDATES);
    }

    /**
     * The notified IDs that one of the findings of the scan still has.
     *
     * @param  list<string>  $notified
     * @return list<string>
     */
    private static function present(ImageScan $scan, array $notified): array
    {
        $found = [];
        foreach (ResourceScans::findings($scan) as $finding) {
            foreach ([$finding->id, ...$finding->aliases] as $id) {
                $found[mb_strtolower($id)] = true;
            }
        }

        return array_values(array_filter(array_map('strval', $notified), fn (string $id): bool => isset($found[mb_strtolower($id)])));
    }

    /**
     * Lower-case IDs as keys, built once per scan.
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
