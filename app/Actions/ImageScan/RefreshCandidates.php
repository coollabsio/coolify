<?php

namespace App\Actions\ImageScan;

use App\Enums\ImageScanStatus;
use App\Events\ImageScanUpdated;
use App\Jobs\ImageRescanJob;
use App\Models\Application;
use App\Models\ImageSbom;
use App\Models\ImageScan;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Services\DockerRegistryLogins;
use App\Services\ImageScan\CandidateEvaluator;
use App\Services\ImageScan\Finding;
use App\Services\ImageScan\GrypeReportParser;
use App\Services\ImageScan\IgnoreMatcher;
use App\Services\ImageScan\ImageRef;
use App\Services\ImageScan\ImageScanPolicyResolver;
use App\Services\ImageScan\ResourceScans;
use App\Services\ImageScan\ScanFailed;
use App\Services\ImageScan\ScanLockBusy;
use App\Services\ImageScan\ScannerCommands;
use App\Services\ImageScan\ScanNotifications;
use App\Services\ImageScan\SyftPackageParser;
use App\Services\ImageScan\TagSelector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * Finds newer versions of a scanned image that fix vulnerabilities (spec 7.1, 7.2, and the candidate rules of
 * spec 11): the same tag with a newer build, the newest tag of the same line, and the next major for information.
 * Candidates are scanned from the registry on the server, at most 2 per image and 20 per server and day; the SBOM of
 * a candidate digest is kept per server and reused.
 *
 * Writes `image_scans.candidates` as `{status, checked_at, error, tags_truncated, entries}`, with status `skipped` when
 * only the base image was checked. The base image of a Dockerfile build (spec 7.4) is scanned from the registry with the
 * newest tag of its line; the result is under `base`: `{image, status, checked_at, error, tags_truncated, attributable,
 * attributable_ids, current_digest, entries}`. A finding of the app is attributable to the base image when the current
 * base image has it too; the line candidate fixes the attributable findings it does not have. Base entries never make a
 * finding fixable now. Never throws for registry or scan problems: they become the status. Registry credentials are read only after an anonymous request failed, only for the registry of the image, and are
 * never stored or logged.
 */
class RefreshCandidates
{
    use AsAction;

    public const MAX_CANDIDATE_SCANS = 2;

    public const DAILY_CANDIDATE_SCANS = 20;

    /** A check newer than this is not repeated after a scan. The daily rescan is always older. */
    public const FRESH_HOURS = 20;

    /** ScannerCommands budgets plus 60 s. */
    private const TIMEOUT_REGISTRY = 90;

    private const TIMEOUT_SBOM = 1260;

    private const TIMEOUT_GRYPE = 360;

    private const TIMEOUT_DEFAULT = 120;

    private const EXIT_TIMEOUT = [124, 137];

    private const EXIT_DOCKER_RUN = 125;

    private ScannerCommands $commands;

    private ImageRef $image;

    private ?string $auth = null;

    private bool $loginRead = false;

    private bool $authRequired = false;

    /** A registry call without credentials worked, so a later failure is not a missing login. */
    private bool $anonymousWorked = false;

    private bool $tagsTruncated = false;

    private ?string $currentImageId = null;

    private ?int $currentImageSize = null;

    private ?string $error = null;

    private bool $retryWhenBusy = false;

    /**
     * @param  int  $lockWaitSeconds  A deploy gate waits for the server lock; 0 tries once (background checks).
     * @param  bool  $retryWhenBusy  Throw ScanLockBusy instead of storing `lock_busy`, so the caller retries later.
     *
     * @throws ScanLockBusy
     */
    public function handle(ImageScan $scan, int $lockWaitSeconds = 0, bool $retryWhenBusy = false): void
    {
        $this->retryWhenBusy = $retryWhenBusy && $lockWaitSeconds <= 0;
        $scan = ImageScan::query()->with(['resource', 'server.settings', 'sbom'])->find($scan->id);
        if (! $scan instanceof ImageScan || ! self::needsCandidates($scan)) {
            return;
        }

        $server = $scan->server;
        $resource = $scan->resource;
        if (! $server instanceof Server || ! ImageRescanJob::canRunOn($server) || ! ImageScanPolicyResolver::for($resource, $server)->scanEnabled) {
            return;
        }

        $this->commands = new ScannerCommands($server, Str::lower(Str::random(24)));
        $ranOnServer = false;
        $result = ['status' => 'skipped', 'error' => null, 'entries' => [], 'tags_truncated' => false];
        $base = null;

        try {
            if (self::needsImageCandidates($scan)) {
                $result = $this->guarded(fn () => $this->check($scan, $resource, $lockWaitSeconds, $ranOnServer));
            }
            if (self::needsBaseCandidates($scan)) {
                $base = $this->guarded(fn () => $this->checkBase($scan, $resource, $lockWaitSeconds, $ranOnServer));
            }
        } finally {
            if ($ranOnServer) {
                instant_remote_process($this->commands->cleanup(), $server, throwError: false, timeout: self::TIMEOUT_DEFAULT);
            }
        }

        $this->store($scan, $result, $base);
    }

    /**
     * Runs one check with a fresh registry state and adds whether the tag list was cut.
     *
     * @param  callable(): array{status: string, error: ?string, entries: list<array<string, mixed>>}  $check
     * @return array{status: string, error: ?string, entries: list<array<string, mixed>>, tags_truncated: bool}
     */
    private function guarded(callable $check): array
    {
        $this->auth = null;
        $this->loginRead = false;
        $this->authRequired = false;
        $this->anonymousWorked = false;
        $this->tagsTruncated = false;
        $this->error = null;

        try {
            $result = $check();
        } catch (ScanLockBusy $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            $result = ['status' => 'failed', 'error' => 'unknown', 'entries' => [], 'attributable' => null, 'attributable_ids' => [], 'current_digest' => null];
        }

        return [...$result, 'tags_truncated' => $this->tagsTruncated];
    }

    public static function needsCandidates(ImageScan $scan): bool
    {
        return self::needsImageCandidates($scan) || self::needsBaseCandidates($scan);
    }

    /**
     * Candidates are looked for only for images the user does not build in Coolify, with a package fix at or above
     * the alert level that is not ignored (spec 7.1).
     */
    private static function needsImageCandidates(ImageScan $scan): bool
    {
        $resource = $scan->resource;
        if ($scan->status !== ImageScanStatus::Completed || ! $resource instanceof Model) {
            return false;
        }
        if ($resource instanceof Application && $resource->build_pack !== 'dockerimage') {
            return false;
        }

        $level = ImageScanPolicyResolver::for($resource, $scan->server)->alertLevel;
        $ignores = IgnoreMatcher::forResource($resource);

        foreach (ResourceScans::findings($scan) as $finding) {
            if ($finding->fixState === 'fixed' && $finding->fixedIn !== null && $level->includes($finding->severity) && $ignores->matches($finding) === null) {
                return true;
            }
        }

        return false;
    }

    /**
     * A newer base image is looked for when a Dockerfile build with a known base image has findings at or above the
     * alert level that are not ignored (spec 7.4). Nixpacks, Railpack, and static builds have no base image.
     */
    private static function needsBaseCandidates(ImageScan $scan): bool
    {
        $resource = $scan->resource;

        return $scan->status === ImageScanStatus::Completed
            && $resource instanceof Application
            && $resource->build_pack === 'dockerfile'
            && filled($scan->base_image_ref)
            && self::alertFindings($scan, $resource) !== [];
    }

    /**
     * Findings at or above the alert level that are not ignored.
     *
     * @return list<Finding>
     */
    public static function alertFindings(ImageScan $scan, Model $resource): array
    {
        $level = ImageScanPolicyResolver::for($resource, $scan->server)->alertLevel;
        $ignores = IgnoreMatcher::forResource($resource);

        return array_values(array_filter(
            ResourceScans::findings($scan),
            fn (Finding $finding): bool => $level->includes($finding->severity) && $ignores->matches($finding) === null,
        ));
    }

    /**
     * The last check finished less than FRESH_HOURS ago with a result that does not need a retry, also for the base image.
     */
    public static function isFresh(ImageScan $scan): bool
    {
        $candidates = $scan->candidates;
        $checkedAt = is_array($candidates) ? ($candidates['checked_at'] ?? null) : null;
        $fresh = ['ok', 'no_better', 'cannot_compare', 'skipped'];
        if (! is_string($checkedAt) || ! in_array($scan->candidateStatus(), $fresh, true)) {
            return false;
        }
        if (self::needsBaseCandidates($scan) && ! in_array(data_get($candidates, 'base.status'), $fresh, true)) {
            return false;
        }

        try {
            return now()->subHours(self::FRESH_HOURS)->lt($checkedAt);
        } catch (Throwable) {
            return false;
        }
    }

    public static function shouldRefresh(ImageScan $scan): bool
    {
        return $scan->exists && ! self::isFresh($scan) && self::needsCandidates($scan);
    }

    /**
     * Fix panel text for a check without a better candidate (spec 7.3, 11). Null when a candidate is better or nothing was checked.
     */
    public static function message(ImageScan $scan): ?string
    {
        try {
            $image = ImageRef::parse((string) $scan->image_ref);
        } catch (InvalidArgumentException) {
            return null;
        }
        $name = (string) ImageRef::parse($image->qualifiedName());
        $server = (string) ($scan->server?->name ?? 'the server');

        return match ($scan->candidateStatus()) {
            'no_better' => "No newer version of {$name} is better yet. Coolify checks again every day.",
            'cannot_compare' => 'Coolify cannot compare versions of this image. Check the image page for a newer version.',
            'auth_required' => "Coolify cannot read the tags of {$name}. Log in to {$image->registry} on {$server}.",
            'failed' => data_get($scan->candidates, 'error') === 'daily_limit'
                ? "Coolify reached the daily limit of version checks on {$server}. It checks again tomorrow."
                : "Coolify could not check for newer versions of {$name}. It tries again tomorrow.",
            default => null,
        };
    }

    /**
     * @return array{status: string, error: ?string, entries: list<array<string, mixed>>}
     */
    private function check(ImageScan $scan, Model $resource, int $lockWaitSeconds, bool &$ranOnServer): array
    {
        try {
            $this->image = ImageRef::parse((string) $scan->image_ref);
        } catch (InvalidArgumentException) {
            return ['status' => 'cannot_compare', 'error' => null, 'entries' => []];
        }
        if ($this->image->digest !== null) {
            return ['status' => 'cannot_compare', 'error' => null, 'entries' => []];
        }

        $platform = $scan->sbom?->platform;
        if (! is_string($platform) || $platform === '') {
            return ['status' => 'failed', 'error' => 'unknown', 'entries' => []];
        }

        $ranOnServer = true;
        $this->currentImageId = $scan->image_id;
        $tag = $this->image->tag ?? 'latest';
        $selector = new TagSelector;
        $isVersion = $selector->isVersion($tag);
        $selected = ['line' => null, 'major' => null, 'floating' => false];

        if ($isVersion) {
            $tags = $this->registry(fn () => $this->commands->tags($this->image->qualifiedName(), $this->auth), 'tags');
            if ($tags === null) {
                return $this->failure();
            }
            $tags = array_values(array_filter(array_map('trim', explode("\n", $tags)), fn (string $line) => $line !== ''));
            $this->tagsTruncated = count($tags) > TagSelector::MAX_TAGS;
            $selected = $selector->select($tag, $tags);
        }

        $toScan = [];
        if ($scan->repo_digest !== null) {
            $digest = $this->digest($tag);
            if ($digest === null && ($this->authRequired || ! $isVersion)) {
                return $this->failure();
            }
            if ($digest !== null && $digest !== $scan->repo_digest) {
                $toScan[] = ['kind' => 'same_tag', 'tag' => $tag, 'digest' => $digest];
            }
        }

        if ($selected['line'] !== null && $selected['line'] !== $tag) {
            $digest = $this->digest($selected['line']);
            if ($digest === null) {
                $this->error ??= 'registry';
            } elseif ($digest !== $scan->repo_digest && ! in_array($digest, array_column($toScan, 'digest'), true)) {
                $toScan[] = ['kind' => 'line', 'tag' => $selected['line'], 'digest' => $digest];
            }
        }

        $entries = $this->evaluate($scan, $resource, array_slice($toScan, 0, self::MAX_CANDIDATE_SCANS), $platform, $lockWaitSeconds);

        if ($selected['major'] !== null) {
            $entries[] = [
                'ref' => (string) $this->image->withTag($selected['major']),
                'digest' => null,
                'kind' => 'major',
                'better' => false,
                'fixed_ids' => [],
                'fixes' => null,
                'left' => null,
                'eol' => null,
                'scanned_at' => null,
            ];
        }

        $scanned = array_filter($entries, fn (array $entry) => $entry['kind'] !== 'major');

        return [
            'status' => match (true) {
                in_array(true, array_column($entries, 'better'), true) => 'ok',
                $this->authRequired => 'auth_required',
                $this->error !== null => 'failed',
                ! $isVersion && $scanned === [] => 'cannot_compare',
                default => 'no_better',
            },
            'error' => $this->error,
            'entries' => $entries,
        ];
    }

    /**
     * Scans the current base image and the newest tag of its line from the registry (spec 7.4): two candidate scans
     * with the usual lock, disk, reuse, and daily rules. Only a different tag can change the `FROM` line, so a newer
     * build of the same tag is not checked.
     *
     * @return array{status: string, error: ?string, entries: list<array<string, mixed>>, attributable: ?int,
     *     attributable_ids: list<string>, current_digest: ?string}
     */
    private function checkBase(ImageScan $scan, Model $resource, int $lockWaitSeconds, bool &$ranOnServer): array
    {
        $result = fn (string $status, array $entries = [], ?array $attributable = null, ?string $currentDigest = null): array => [
            'status' => $status,
            'error' => $status === 'cannot_compare' ? null : $this->error,
            'entries' => $entries,
            'attributable' => $attributable === null ? null : count($attributable),
            'attributable_ids' => $attributable === null ? [] : array_values(array_unique(array_map(fn (Finding $finding) => $finding->id, $attributable))),
            'current_digest' => $currentDigest,
        ];
        $failure = fn (?array $attributable = null, ?string $currentDigest = null): array => [
            ...$result('failed', [], $attributable, $currentDigest),
            ...$this->failure(),
        ];

        try {
            $this->image = ImageRef::parse((string) $scan->base_image_ref);
        } catch (InvalidArgumentException) {
            return $result('cannot_compare');
        }

        $tag = $this->image->tag ?? 'latest';
        $selector = new TagSelector;
        if ($this->image->digest !== null || ! $selector->isVersion($tag)) {
            return $result('cannot_compare');
        }

        $platform = $scan->sbom?->platform;
        if (! is_string($platform) || $platform === '') {
            $this->error = 'unknown';

            return $result('failed');
        }

        $ranOnServer = true;
        // The disk check of the registry scans uses the size of the app image, an upper bound of its base image.
        $this->currentImageId = $scan->image_id;
        $this->currentImageSize = null;
        $tags = $this->registry(fn () => $this->commands->tags($this->image->qualifiedName(), $this->auth), 'tags');
        if ($tags === null) {
            return $failure();
        }
        $tags = array_values(array_filter(array_map('trim', explode("\n", $tags)), fn (string $line) => $line !== ''));
        $this->tagsTruncated = count($tags) > TagSelector::MAX_TAGS;
        $line = $selector->select($tag, $tags)['line'];
        if ($line === null || $line === $tag) {
            return $result('no_better');
        }

        $currentDigest = $this->digest($tag);
        $lineDigest = $currentDigest === null ? null : $this->digest($line);
        if ($currentDigest === null || $lineDigest === null) {
            return $failure(null, $currentDigest);
        }
        if ($lineDigest === $currentDigest) {
            return $result('no_better', [], null, $currentDigest);
        }

        $currentBase = $this->scanCandidate($currentDigest, $platform, $lockWaitSeconds);
        if ($currentBase === null) {
            return $failure(null, $currentDigest);
        }

        $level = ImageScanPolicyResolver::for($resource, $scan->server)->alertLevel;
        $ignores = IgnoreMatcher::forResource($resource);
        $notIgnored = fn (array $findings) => array_values(array_filter($findings, fn (Finding $finding) => $ignores->matches($finding) === null));
        $evaluator = new CandidateEvaluator;
        $currentBaseFindings = $notIgnored($currentBase['findings']);
        $attributable = $evaluator->shared(self::alertFindings($scan, $resource), $currentBaseFindings);

        $candidate = $this->scanCandidate($lineDigest, $platform, $lockWaitSeconds);
        if ($candidate === null) {
            return $failure($attributable, $currentDigest);
        }

        $candidateFindings = $notIgnored($candidate['findings']);
        $better = ! $currentBase['truncated'] && ! $candidate['truncated']
            && $evaluator->isBetter($evaluator->summarize($currentBaseFindings, $level), $candidateFindings, $level, $currentBase['eol'] !== null, $candidate['eol'] !== null);
        $entry = [
            'ref' => (string) $this->image->withTag($line),
            'digest' => $lineDigest,
            'kind' => 'line',
            'better' => $better,
            'fixed_ids' => $evaluator->fixedIds($attributable, $candidateFindings),
            'fixes' => $evaluator->counts($evaluator->fixed($attributable, $candidateFindings)),
            'left' => $evaluator->counts($evaluator->atLevel($candidateFindings, $level)),
            'eol' => $candidate['eol'],
            'scanned_at' => now()->toIso8601String(),
        ];

        return $result($better ? 'ok' : 'no_better', [$entry], $attributable, $currentDigest);
    }

    /**
     * @param  list<array{kind: string, tag: string, digest: string}>  $candidates
     * @return list<array<string, mixed>>
     */
    private function evaluate(ImageScan $scan, Model $resource, array $candidates, string $platform, int $lockWaitSeconds): array
    {
        if ($candidates === []) {
            return [];
        }

        $level = ImageScanPolicyResolver::for($resource, $scan->server)->alertLevel;
        $ignores = IgnoreMatcher::forResource($resource);
        $notIgnored = fn (array $findings) => array_values(array_filter($findings, fn (Finding $finding) => $ignores->matches($finding) === null));
        $evaluator = new CandidateEvaluator;
        $current = $notIgnored(ResourceScans::findings($scan));
        $currentAtLevel = $evaluator->atLevel($current, $level);
        $currentSummary = $evaluator->summarize($current, $level);
        $currentEol = is_array(data_get($scan->summary, 'eol_distro'));

        $entries = [];
        foreach ($candidates as $candidate) {
            $report = $this->scanCandidate($candidate['digest'], $platform, $lockWaitSeconds);
            if ($report === null) {
                continue;
            }

            $findings = $notIgnored($report['findings']);
            $fixed = $evaluator->fixed($currentAtLevel, $findings);
            $entries[] = [
                'ref' => (string) $this->image->withTag($candidate['tag']),
                'digest' => $candidate['digest'],
                'kind' => $candidate['kind'],
                'better' => ! $report['truncated'] && $evaluator->isBetter($currentSummary, $findings, $level, $currentEol, $report['eol'] !== null),
                'fixed_ids' => $evaluator->fixedIds($currentAtLevel, $findings),
                'fixes' => $evaluator->counts($fixed),
                'left' => $evaluator->counts($evaluator->atLevel($findings, $level)),
                'eol' => $report['eol'],
                'scanned_at' => now()->toIso8601String(),
            ];
        }

        return $entries;
    }

    /**
     * Runs Grype on the SBOM of a candidate digest; makes the SBOM from the registry first when this server has none.
     * Takes the scan lock of the server, so a deploy scan waits at most one candidate scan. A background check never
     * waits for the lock and leaves it to a deploy gate that waits.
     *
     * @return array{findings: list<Finding>, eol: ?array{name: string, version: string}, truncated: bool}|null
     */
    private function scanCandidate(string $digest, string $platform, int $lockWaitSeconds): ?array
    {
        $server = $this->commands->server;
        $lock = ScanImage::serverLock($server);
        if (! ScanImage::acquireServerLock($lock, $server, $lockWaitSeconds)) {
            if ($this->retryWhenBusy) {
                throw new ScanLockBusy;
            }
            $this->error ??= 'lock_busy';

            return null;
        }

        try {
            $hex = substr($digest, strlen('sha256:'));
            $sbom = ImageSbom::query()->where('server_id', $server->id)->where('image_id', $digest)->first();

            if ($sbom !== null) {
                $grype = $this->runStep($this->commands->grype($hex), 'grype', self::TIMEOUT_GRYPE);
                if ($grype !== null && $grype['code'] === 0) {
                    $sbom->forceFill(['last_used_at' => now()])->save();

                    return $this->report($grype);
                }
                if ($grype === null || $grype['code'] !== self::EXIT_DOCKER_RUN) {
                    $this->error ??= 'scan';

                    return null;
                }
            }

            if (! $this->hasDiskSpace()) {
                $this->error ??= 'disk';

                return null;
            }

            if (! $this->reserveDailyScan($server)) {
                $this->error ??= 'daily_limit';

                return null;
            }

            $created = $this->runStep($this->commands->sbomFromRegistry($this->image->qualifiedName().'@'.$digest, $platform, $hex, $this->auth), 'sbom', self::TIMEOUT_SBOM);
            $packages = $created !== null && $created['code'] === 0 ? $this->runStep($this->commands->packages($hex), 'packages', self::TIMEOUT_GRYPE) : null;
            if ($packages === null || $packages['code'] !== 0) {
                $this->error ??= 'scan';

                return null;
            }

            $parsed = (new SyftPackageParser)->parse($this->payload($packages)[0]);
            ($sbom ?? new ImageSbom(['server_id' => $server->id, 'image_id' => $digest, 'base_layer_ids' => []]))->forceFill([
                'platform' => $platform,
                'packages' => array_map(fn ($package) => $package->toArray(), $parsed['packages']),
                'packages_truncated' => $parsed['truncated'] || $this->payload($packages)[1],
                'last_used_at' => now(),
            ])->save();

            $grype = $this->runStep($this->commands->grype($hex), 'grype', self::TIMEOUT_GRYPE);
            if ($grype === null || $grype['code'] !== 0) {
                $this->error ??= 'scan';

                return null;
            }

            return $this->report($grype);
        } finally {
            $lock->release();
        }
    }

    /**
     * The disk rule of local scans (image size × 2 + 1 GB), with the size of the current image: Syft writes the
     * candidate's layers to the scratch directory. A current image that is no longer on the server counts as 0 bytes.
     */
    private function hasDiskSpace(): bool
    {
        if ($this->currentImageSize === null) {
            $this->currentImageSize = 0;
            if (is_string($this->currentImageId) && preg_match('/\Asha256:[a-f0-9]{64}\z/', $this->currentImageId) === 1) {
                $inspect = $this->runStep($this->commands->inspect($this->currentImageId), 'inspect', self::TIMEOUT_DEFAULT);
                $parts = $inspect !== null && $inspect['code'] === 0 ? explode('|', trim($inspect['payload'])) : [];
                if (count($parts) === 4 && preg_match('/\A\d{1,18}\z/', $parts[3]) === 1) {
                    $this->currentImageSize = (int) $parts[3];
                }
            }
        }

        try {
            ScanImage::checkDiskSpace($this->commands, $this->currentImageSize);
        } catch (ScanFailed) {
            return false;
        }

        return true;
    }

    /**
     * Counts a registry scan against the daily limit of the server; false when the limit is reached.
     */
    private function reserveDailyScan(Server $server): bool
    {
        $today = today()->toDateString();

        ServerSetting::query()
            ->where('server_id', $server->id)
            ->where(fn ($query) => $query->whereNull('image_candidate_scans_date')->orWhereDate('image_candidate_scans_date', '<', $today))
            ->update(['image_candidate_scans_today' => 0, 'image_candidate_scans_date' => $today]);

        return ServerSetting::query()
            ->where('server_id', $server->id)
            ->where('image_candidate_scans_today', '<', self::DAILY_CANDIDATE_SCANS)
            ->increment('image_candidate_scans_today') > 0;
    }

    private function digest(string $tag): ?string
    {
        $payload = $this->registry(fn () => $this->commands->digest((string) $this->image->withTag($tag), null, $this->auth), 'digest');
        $digest = $payload === null ? '' : trim($payload);

        return ImageRef::isValidDigest($digest) ? $digest : null;
    }

    /**
     * Runs a registry command anonymously first. After a failure it reads the server's login for the registry of the
     * image once and repeats the command with it. Without a usable login, a private image needs one. When an anonymous
     * call already worked, a failure is a registry error, not a missing login.
     *
     * @param  callable(): list<string>  $commands
     */
    private function registry(callable $commands, string $step): ?string
    {
        $result = $this->runStep($commands(), $step, self::TIMEOUT_REGISTRY);
        if ($result !== null && $result['code'] === 0) {
            $this->anonymousWorked = $this->anonymousWorked || $this->auth === null;

            return $this->payload($result)[0];
        }
        if ($result === null || in_array($result['code'], self::EXIT_TIMEOUT, true)) {
            $this->error ??= 'registry';

            return null;
        }
        if ($this->loginRead || $this->anonymousWorked) {
            $this->error ??= 'registry';

            return null;
        }

        $this->loginRead = true;
        $login = DockerRegistryLogins::registryAuthConfig($this->commands->server, $this->image->registry);
        if ($login['status'] !== 'ok') {
            $this->authRequired = $login['status'] === 'helper' || ! $this->image->isDockerOfficial();
            $this->error ??= 'registry';

            return null;
        }

        $this->auth = $login['config'];
        $result = $this->runStep($commands(), $step, self::TIMEOUT_REGISTRY);
        if ($result !== null && $result['code'] === 0) {
            return $this->payload($result)[0];
        }

        $this->authRequired = true;
        $this->error ??= 'registry';

        return null;
    }

    /**
     * @return array{status: string, error: ?string, entries: list<array<string, mixed>>}
     */
    private function failure(): array
    {
        return [
            'status' => $this->authRequired ? 'auth_required' : 'failed',
            'error' => $this->authRequired ? 'registry_auth' : ($this->error ?? 'registry'),
            'entries' => [],
        ];
    }

    /**
     * @param  array{code: int, size: ?int, payload: string}  $grype
     * @return array{findings: list<Finding>, eol: ?array{name: string, version: string}, truncated: bool}
     */
    private function report(array $grype): array
    {
        [$payload, $cut] = $this->payload($grype);
        $report = (new GrypeReportParser)->parse($payload);

        return ['findings' => $report['findings'], 'eol' => $report['eol'], 'truncated' => $report['truncated'] || $cut];
    }

    /**
     * Null when the batch did not run or printed no marker for $step.
     *
     * @param  list<string>  $commands
     * @return array{code: int, size: ?int, payload: string}|null
     */
    private function runStep(array $commands, string $step, int $timeout): ?array
    {
        $output = instant_remote_process($commands, $this->commands->server, throwError: false, timeout: $timeout);
        $parsed = ScannerCommands::parseOutput($output);
        if (! array_key_exists($step, $parsed['exit_codes'])) {
            return null;
        }

        return ['code' => $parsed['exit_codes'][$step], 'size' => $parsed['sizes'][$step] ?? null, 'payload' => $parsed['payload']];
    }

    /**
     * Output over the limit was cut by `head -c`: keep only its complete lines.
     *
     * @param  array{code: int, size: ?int, payload: string}  $result
     * @return array{0: string, 1: bool}
     */
    private function payload(array $result): array
    {
        $payload = $result['payload'];
        $cut = ($result['size'] ?? 0) > ScannerCommands::OUTPUT_LIMIT || strlen($payload) > ScannerCommands::OUTPUT_LIMIT;
        if ($cut) {
            $payload = substr($payload, 0, ScannerCommands::OUTPUT_LIMIT);
            $payload = substr($payload, 0, (int) strrpos($payload, "\n"));
        }

        return [$payload, $cut];
    }

    /**
     * Writes the result on the current row and recomputes its summary: a better candidate makes findings of images
     * the user does not build fixable now (spec 7.5).
     *
     * @param  array{status: string, error: ?string, entries: list<array<string, mixed>>, tags_truncated: bool}  $result
     * @param  array{status: string, error: ?string, entries: list<array<string, mixed>>, tags_truncated: bool,
     *     attributable: ?int, attributable_ids: list<string>, current_digest: ?string}|null  $base
     */
    private function store(ImageScan $scan, array $result, ?array $base): void
    {
        $fresh = ImageScan::query()->find($scan->id);
        if (! $fresh instanceof ImageScan || ! $scan->resource instanceof Model) {
            return;
        }

        $checkedAt = now()->toIso8601String();
        $fresh->candidates = [
            'status' => $result['status'],
            'checked_at' => $checkedAt,
            'error' => $result['error'],
            'tags_truncated' => $result['tags_truncated'],
            'entries' => $result['entries'],
            ...($base === null ? [] : ['base' => [
                'image' => (string) $scan->base_image_ref,
                'status' => $base['status'],
                'checked_at' => $checkedAt,
                'error' => $base['error'],
                'tags_truncated' => $base['tags_truncated'],
                'attributable' => $base['attributable'],
                'attributable_ids' => $base['attributable_ids'],
                'current_digest' => $base['current_digest'],
                'entries' => $base['entries'],
            ]]),
        ];
        $fresh->setRelation('resource', $scan->resource);
        ResourceScans::refreshSummaries(collect([$fresh]));

        // A newer image can make a known exploited finding fixable now.
        try {
            ScanNotifications::afterScan($fresh, candidateCheck: true);
        } catch (Throwable $e) {
            report($e);
        }

        $teamId = method_exists($scan->resource, 'team') ? $scan->resource->team()?->id : null;
        ImageScanUpdated::dispatch($teamId, (string) $scan->resource->getAttribute('uuid'), (string) $fresh->uuid);
    }
}
