<?php

namespace App\Services\ImageScan;

use App\Actions\ImageScan\UpdateResourceImage;
use App\Enums\ImageScanFixState;
use App\Enums\ImageScanStatus;
use App\Models\ImageScan;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\Team;
use App\Notifications\ImageScan\KnownExploitedFound;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * What the image vulnerability notifications have told a team already (spec 8).
 *
 * `image_scans.notified_ids` holds the IDs of the fixable-now findings at the alert level that a notification (or the
 * baseline) covered. A finding is new when none of its IDs and aliases is in the list. IDs of findings that are no
 * longer in the scan are dropped, so a vulnerability that comes back notifies again. A scan with `is_baseline` or
 * without a list is a baseline: its findings are recorded without a notification. The better candidates a digest
 * named are kept under `candidates.notified`.
 */
final class ScanNotifications
{
    public const SEVERITIES = ['critical', 'high', 'medium', 'low', 'unknown'];

    /**
     * After a scan stored its results: records a baseline, drops the IDs that are no longer found, and alerts the
     * team right away for a new known exploited finding that is fixable now.
     */
    public static function afterScan(ImageScan $scan): void
    {
        if (! $scan->exists || $scan->status !== ImageScanStatus::Completed) {
            return;
        }

        $owner = $scan->resource;
        if (! $owner instanceof Model) {
            return;
        }

        $fixable = self::fixableNow($scan, $owner);
        $notified = $scan->notified_ids;
        if (! $scan->is_baseline && $notified === null) {
            $notified = self::previousNotifiedIds($scan);
        }

        if ($scan->is_baseline || $notified === null) {
            self::saveNotifiedIds($scan, self::ids($fixable));

            return;
        }

        $notified = self::present($scan, $notified);
        $known = array_values(array_filter($fixable, fn (Finding $finding): bool => $finding->kev && ! self::isIn($finding, $notified)));
        if ($known !== []) {
            $notified = [...$notified, ...self::ids($known)];
        }
        self::saveNotifiedIds($scan, $notified);

        $team = method_exists($owner, 'team') ? $owner->team() : null;
        if ($known !== [] && $team instanceof Team) {
            $team->notify(new KnownExploitedFound($scan, count($known)));
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

    public static function isBaseline(ImageScan $scan): bool
    {
        return (bool) $scan->is_baseline || $scan->notified_ids === null;
    }

    /**
     * The fixable-now findings at the alert level that no notification covered yet. Empty for a baseline.
     *
     * @param  list<Finding>  $fixable
     * @return list<Finding>
     */
    public static function newFindings(ImageScan $scan, array $fixable): array
    {
        if (self::isBaseline($scan)) {
            return [];
        }

        $notified = (array) $scan->notified_ids;

        return array_values(array_filter($fixable, fn (Finding $finding): bool => ! self::isIn($finding, $notified)));
    }

    /**
     * Records the findings and candidates a digest covered (or a baseline saw), and drops IDs no longer found.
     *
     * @param  list<Finding>  $fixable
     * @param  list<string>  $candidateKeys
     */
    public static function markNotified(ImageScan $scan, array $fixable, array $candidateKeys): void
    {
        $notified = self::isBaseline($scan) ? [] : self::present($scan, (array) $scan->notified_ids);
        $new = array_filter($fixable, fn (Finding $finding): bool => ! self::isIn($finding, $notified));

        $candidates = $scan->candidates;
        if (is_array($candidates) && $candidates !== []) {
            $candidates = array_is_list($candidates) ? ['entries' => $candidates] : $candidates;
            if (($candidates['notified'] ?? null) !== $candidateKeys) {
                $scan->candidates = [...$candidates, 'notified' => $candidateKeys];
            }
        }

        self::saveNotifiedIds($scan, [...$notified, ...self::ids(array_values($new))]);
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
     * The candidates a digest has not named yet. Empty for a baseline.
     *
     * @param  array<string, string>  $candidates
     * @return array<string, string>
     */
    public static function newCandidates(ImageScan $scan, array $candidates): array
    {
        if (self::isBaseline($scan)) {
            return [];
        }

        $notified = data_get($scan->candidates, 'notified');
        $notified = is_array($notified) ? array_flip(array_map('strval', $notified)) : [];

        return array_diff_key($candidates, $notified);
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
     * The notified IDs of the latest other completed scan of the same resource, for a new image of the resource.
     *
     * @return list<string>|null
     */
    private static function previousNotifiedIds(ImageScan $scan): ?array
    {
        $previous = ImageScan::query()
            ->where('resource_type', $scan->resource_type)
            ->where('resource_id', $scan->resource_id)
            ->whereKeyNot($scan->getKey())
            ->where('status', ImageScanStatus::Completed)
            ->whereNotNull('notified_ids')
            ->orderByDesc('scanned_at')
            ->orderByDesc('id')
            ->value('notified_ids');

        $ids = is_string($previous) ? json_decode($previous, true) : $previous;

        return is_array($ids) ? array_values(array_map('strval', $ids)) : null;
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
     * @param  list<string>  $ids
     */
    private static function isIn(Finding $finding, array $ids): bool
    {
        $ids = array_flip(array_map(fn ($id): string => mb_strtolower((string) $id), $ids));
        foreach ([$finding->id, ...$finding->aliases] as $id) {
            if (isset($ids[mb_strtolower($id)])) {
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

    /**
     * @param  list<string>  $ids
     */
    private static function saveNotifiedIds(ImageScan $scan, array $ids): void
    {
        $ids = array_values(array_unique($ids));
        if ($scan->notified_ids !== $ids) {
            $scan->notified_ids = $ids;
        }

        if ($scan->isDirty()) {
            $scan->saveQuietly();
        }
    }
}
