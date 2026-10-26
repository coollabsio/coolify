<?php

namespace App\Jobs;

use App\Models\ImageScan;
use App\Models\ImageScanIgnore;
use App\Models\Server;
use App\Models\Team;
use App\Notifications\ImageScan\VulnerabilityDigest;
use App\Services\ImageScan\Finding;
use App\Services\ImageScan\ScanNotifications;
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
 * The daily image vulnerability digest of one team (spec 8): new fixable-now findings at the alert level and new
 * fixes per scanned resource of the team, ignores that end within 7 days, and servers whose rescans failed 2 days in
 * a row. Sends one VulnerabilityDigest only when something changed, then records what it covered (see
 * ScanNotifications). An ignore is named once per end date (cache); a failing server once per failure run.
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

        /** @var list<callable(): void> $marks */
        $marks = [];
        $resources = [];
        foreach (TeamVulnerabilities::load($this->team->id)->entries as $entry) {
            if (! $entry['scanned']) {
                continue;
            }

            try {
                $resource = $this->resourceDigest($entry['resource'], $entry['scans'], $marks);
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
            usort($resources, fn (array $a, array $b): int => [self::rank($b['worst']), $b['total'], $a['name']] <=> [self::rank($a['worst']), $a['total'], $b['name']]);

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
                $mark();
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
     * New findings and fixes of one resource page (a service covers its applications and databases).
     *
     * @param  Collection<int, ImageScan>  $current  The current scans, with their owner.
     * @param  list<callable(): void>  $marks
     * @return array<string, mixed>|null
     */
    private function resourceDigest(Model $resource, Collection $current, array &$marks): ?array
    {
        $owners = $current->mapWithKeys(fn (ImageScan $scan): array => [$scan->id => $scan->resource]);
        $scans = ImageScan::query()->whereIn('id', $owners->keys())->get();

        /** @var list<Finding> $new */
        $new = [];
        $updates = [];
        $fix = null;
        foreach ($scans as $scan) {
            $owner = $owners->get($scan->id);
            if (! $owner instanceof Model) {
                continue;
            }
            $scan->setRelation('resource', $owner);

            $fixable = ScanNotifications::fixableNow($scan, $owner);
            $candidates = ScanNotifications::betterCandidates($scan, $owner);
            $newFindings = ScanNotifications::newFindings($scan, $fixable);
            $updates = [...$updates, ...array_values(ScanNotifications::newCandidates($scan, $candidates))];
            if ($newFindings !== [] && $fix === null) {
                $fix = array_values($candidates)[0] ?? null;
            }
            $new = [...$new, ...$newFindings];
            $marks[] = fn () => ScanNotifications::markNotified($scan, $fixable, array_keys($candidates));
        }

        $updates = array_values(array_unique($updates));
        if ($new === [] && $updates === []) {
            return null;
        }

        $counts = ScanNotifications::counts($new);

        return [
            'name' => (string) $resource->getAttribute('name'),
            'uuid' => (string) $resource->getAttribute('uuid'),
            'url' => ScanNotifications::link($resource),
            'counts' => $counts,
            'total' => count($new),
            'worst' => array_key_first($counts),
            'known_exploited' => count(array_filter($new, fn (Finding $finding): bool => $finding->kev)),
            'fix' => $fix,
            'ids' => array_slice(array_values(array_unique(array_map(fn (Finding $finding): string => $finding->id, $new))), 0, VulnerabilityDigest::MAIL_ID_LIMIT),
            'updates' => $updates,
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
     * Servers of the team whose daily rescan failed 2 days in a row, not named yet for this run of failures.
     *
     * @return Collection<int, Server>
     */
    private function failingServers(): Collection
    {
        return Server::query()
            ->where('team_id', $this->team->id)
            ->get()
            ->filter(fn (Server $server): bool => ! $server->isTransferredAway()
                && ImageRescanJob::failedDays($server) >= 2
                && ! ImageRescanJob::isFailureNotified($server))
            ->values();
    }

    private static function rank(?string $severity): int
    {
        return match ($severity) {
            'critical' => 4,
            'high' => 3,
            'medium' => 2,
            'low' => 1,
            default => 0,
        };
    }
}
