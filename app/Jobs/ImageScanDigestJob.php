<?php

namespace App\Jobs;

use App\Enums\SecurityStatusType;
use App\Models\ImageScan;
use App\Models\ImageScanIgnore;
use App\Models\Server;
use App\Models\Team;
use App\Notifications\ImageScan\VulnerabilityDigest;
use App\Services\ImageScan\ResourceSecurity;
use App\Services\ImageScan\ScanNotifications;
use App\Services\ImageScan\SecuritySentences;
use App\Services\ImageScan\SecurityStatus;
use App\Services\ImageScan\TeamVulnerabilities;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The daily security digest of one team (spec 15.8): resources that became update_available (new problems with an
 * action, or a new fixing version) or check_needed, ignores that end within 7 days, and servers whose rescans failed
 * 2 days in a row. Sends one VulnerabilityDigest only when something changed, then records what it covered in the
 * notification state of each scan owner (see ScanNotifications). An ignore is named once per end date (cache); a
 * failing server once per run of failures.
 *
 * A resource whose status is check_needed only because its version check did not finish yet (right after a scan) is
 * skipped until the check finished. While a resource needs a check, its new problems and fixing versions wait for
 * the next status.
 */
class ImageScanDigestJob implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const IGNORE_WINDOW_DAYS = 7;

    public $tries = 1;

    public $timeout = 3600;

    public $uniqueFor = 3600;

    public function __construct(public Team $team)
    {
        $this->onQueue(isCloud() ? maintenance_queue() : 'default');
    }

    public function uniqueId(): string
    {
        return (string) $this->team->id;
    }

    public function handle(): void
    {
        if (! instanceSettings()->is_image_scanning_allowed) {
            return;
        }

        /** @var list<array<string, mixed>> $marks */
        $marks = [];
        $resources = [];
        foreach (TeamVulnerabilities::load($this->team->id)->entries as $entry) {
            if (! $entry['scanned']) {
                continue;
            }

            try {
                $resource = $this->resourceDigest($entry['resource'], $entry['scans'], $entry['security'], $marks);
            } catch (Throwable $e) {
                report($e);

                continue;
            }
            if ($resource !== null) {
                $resources[] = $resource;
            }
        }

        $ignores = $this->endingIgnores();
        $servers = $this->failingServers();

        if ($resources !== [] || $ignores->isNotEmpty() || $servers->isNotEmpty()) {
            $order = array_flip(TeamVulnerabilities::STATUS_ORDER);
            usort($resources, fn (array $a, array $b): int => [$order[$a['status']] ?? 99, $a['name']] <=> [$order[$b['status']] ?? 99, $b['name']]);

            $this->team->notify(new VulnerabilityDigest([
                'resources' => $resources,
                'ignores' => $ignores->map(fn (ImageScanIgnore $ignore): array => [
                    'resource' => $ignore->resource instanceof Model ? (string) ScanNotifications::pageResource($ignore->resource)->getAttribute('name') : null,
                    'id' => $ignore->vulnerability_id,
                    'package' => $ignore->package,
                    'expires_at' => $ignore->expires_at->toDateString(),
                ])->values()->all(),
                'servers' => $servers->map(fn (Server $server): array => [
                    'name' => (string) $server->name,
                    'url' => rtrim(base_url(), '/').'/server/'.$server->uuid,
                ])->values()->all(),
            ]));

            foreach ($ignores as $ignore) {
                Cache::put(self::ignoreCacheKey($ignore), $ignore->expires_at->toIso8601String(), $ignore->expires_at->copy()->addDay());
            }
            foreach ($servers as $server) {
                ImageRescanJob::markFailureNotified($server);
            }
        }

        foreach ($marks as $mark) {
            try {
                ScanNotifications::applyDigest($mark);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    public static function ignoreCacheKey(ImageScanIgnore $ignore): string
    {
        return "image-scan-digest:ignore:{$ignore->id}";
    }

    /**
     * The digest line of one resource page (a service covers its applications and databases), or null. Each scan
     * owner has its own notification state; its current scans are decoded one owner at a time.
     *
     * @param  Collection<int, ImageScan>  $current  The current scans, with their owner.
     * @param  list<array<string, mixed>>  $marks  Gets the marks of the resource only when its digest succeeded.
     * @return array{name: string, uuid: string, url: ?string, status: string, badge: string, action: ?string, why: list<array<string, mixed>>}|null
     */
    private function resourceDigest(Model $resource, Collection $current, ResourceSecurity $security, array &$marks): ?array
    {
        $resourceMarks = [];
        $digest = $this->buildResourceDigest($resource, $current, $security, $resourceMarks);
        array_push($marks, ...$resourceMarks);

        return $digest;
    }

    /**
     * @param  Collection<int, ImageScan>  $current
     * @param  list<array<string, mixed>>  $marks
     * @return array{name: string, uuid: string, url: ?string, status: string, badge: string, action: ?string, why: list<array<string, mixed>>}|null
     */
    private function buildResourceDigest(Model $resource, Collection $current, ResourceSecurity $security, array &$marks): ?array
    {
        $owners = $current->map(fn (ImageScan $scan) => $scan->resource)
            ->filter(fn ($owner): bool => $owner instanceof Model)
            ->unique(fn (Model $owner): string => $owner->getMorphClass().':'.$owner->getKey());

        $plans = $owners->map(fn (Model $owner): ?array => ScanNotifications::digestPlan($owner))->filter()->values();
        if ($plans->isEmpty()) {
            return null;
        }

        $needsCheck = $security->status === SecurityStatusType::CheckNeeded;
        if ($needsCheck && $security->checkReason === SecurityStatus::REASON_VERSIONS_UNCHECKED && $plans->contains('pending', true)) {
            return null;
        }

        $active = $plans->where('baseline', false);
        $report = match ($security->status) {
            SecurityStatusType::UpdateNow, SecurityStatusType::UpdateAvailable => $active->contains(fn (array $plan): bool => $plan['newFixable'] !== [] || $plan['newCandidates'] !== []),
            SecurityStatusType::CheckNeeded => $active->isNotEmpty() && ! $active->contains('checkReported', true),
            default => false,
        };

        foreach ($plans as $plan) {
            $mark = $plan['mark'];
            $mark['check_reported'] = $needsCheck;
            if ($needsCheck && ! $plan['baseline']) {
                $mark['add_digest_ids'] = [];
                $mark['add_candidates'] = [];
            }
            $marks[] = $mark;
        }

        if (! $report) {
            return null;
        }

        return [
            'name' => (string) $resource->getAttribute('name'),
            'uuid' => (string) $resource->getAttribute('uuid'),
            'url' => ScanNotifications::link($resource),
            'status' => $security->status->value,
            'badge' => (string) SecuritySentences::badge($security->status),
            'action' => TeamVulnerabilities::action($security)['text'] ?? null,
            'why' => $needsCheck ? [] : $security->why,
        ];
    }

    /**
     * Ignores of the team that end within IGNORE_WINDOW_DAYS and no digest named with this end date.
     *
     * @return Collection<int, ImageScanIgnore>
     */
    private function endingIgnores(): Collection
    {
        return ImageScanIgnore::query()
            ->where('team_id', $this->team->id)
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addDays(self::IGNORE_WINDOW_DAYS))
            ->orderBy('expires_at')
            ->get()
            ->reject(fn (ImageScanIgnore $ignore): bool => Cache::get(self::ignoreCacheKey($ignore)) === $ignore->expires_at->toIso8601String())
            ->values();
    }

    /**
     * Servers of the team that still scan images and whose daily rescan failed 2 days in a row, not named yet for
     * this run of failures.
     *
     * @return Collection<int, Server>
     */
    private function failingServers(): Collection
    {
        return Server::query()
            ->where('team_id', $this->team->id)
            ->with('settings')
            ->get()
            ->filter(fn (Server $server): bool => (bool) $server->settings?->is_image_scan_enabled
                && ! $server->isTransferredAway()
                && ImageRescanJob::failedDays($server) >= 2
                && ! ImageRescanJob::isFailureNotified($server))
            ->values();
    }
}
