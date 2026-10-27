<?php

namespace App\Services\ImageScan;

use App\Actions\ImageScan\RefreshCandidates;
use App\Actions\ImageScan\UpdateResourceImage;
use App\Enums\ImageScanAlertLevel;
use App\Models\Application;
use App\Models\ImageScan;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * The fix panel of the Vulnerabilities page (spec 7.3, 11): per scanned image, the version to update to and what it
 * fixes and leaves, or why there is none. Resources with id 0 belong to Coolify and get no panel.
 */
final class FixPanel
{
    private const SEVERITIES = ['critical', 'high', 'medium'];

    /**
     * @param  Collection<int, ImageScan>  $scans  The current scans, loaded with their resource.
     * @return list<array{key: string, image: string, owner: string, update: ?array<string, mixed>, message: ?string, registryUrl: ?string, loginUrl: ?string, major: ?string, base: ?array{from: string, to: string, fixed: int, total: int}}>
     */
    public static function items(Model $resource, Collection $scans, User $user): array
    {
        if ((int) $resource->getKey() === 0) {
            return [];
        }

        $items = [];
        foreach ($scans->unique(fn (ImageScan $scan): string => (string) $scan->image_ref) as $scan) {
            $owner = $scan->resource;
            if (! $owner instanceof Model || (int) $owner->getKey() === 0 || $scan->candidates === null) {
                continue;
            }

            $item = self::item($resource, $owner, $scan, $user);
            if ($item['update'] !== null || $item['message'] !== null || $item['major'] !== null || $item['base'] !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Whether one of the scans has a version to update to.
     *
     * @param  Collection<int, ImageScan>  $scans
     */
    public static function hasUpdate(Model $resource, Collection $scans): bool
    {
        return (int) $resource->getKey() !== 0
            && $scans->contains(fn (ImageScan $scan): bool => (int) $scan->resource_id !== 0
                && $scan->updateCandidates() !== []
                && $scan->resource instanceof Model
                && UpdateResourceImage::blockedReason($scan, $scan->resource) === null);
    }

    /**
     * "fixes 17 critical and high." uses the severities of the alert level.
     */
    public static function levelWords(ImageScanAlertLevel $level): string
    {
        return match ($level) {
            ImageScanAlertLevel::Critical => 'critical',
            ImageScanAlertLevel::High => 'critical and high',
            ImageScanAlertLevel::Medium => 'critical, high, and medium',
        };
    }

    /**
     * "fixes 17 critical and high." with the words of the alert level, or "fixes 1 high vulnerability." for one.
     *
     * @param  array<string, mixed>|null  $fixes
     */
    public static function fixesPhrase(?array $fixes, ImageScanAlertLevel $level): string
    {
        $counts = [];
        foreach (self::SEVERITIES as $severity) {
            if ($level->includes($severity)) {
                $counts[$severity] = (int) ($fixes[$severity] ?? 0);
            }
        }

        $total = array_sum($counts);
        if ($total === 1) {
            return 'fixes 1 '.array_search(1, $counts, true).' vulnerability.';
        }

        return "fixes {$total} ".self::levelWords($level).'.';
    }

    /**
     * "Nothing fixable is left." or "3 critical and 2 high are left, waiting for a fix from the image maintainer."
     *
     * @param  array<string, mixed>|null  $left
     */
    public static function leftSentence(?array $left, ImageScanAlertLevel $level): string
    {
        $parts = [];
        $total = 0;
        foreach (self::SEVERITIES as $severity) {
            $count = (int) ($left[$severity] ?? 0);
            if ($count > 0 && $level->includes($severity)) {
                $parts[] = "{$count} {$severity}";
                $total += $count;
            }
        }

        if ($parts === []) {
            return 'Nothing fixable is left.';
        }

        $list = count($parts) < 3 ? implode(' and ', $parts) : implode(', ', array_slice($parts, 0, -1)).', and '.end($parts);

        return $list.($total === 1 ? ' is' : ' are').' left, waiting for a fix from the image maintainer.';
    }

    /**
     * The image page on the registry website: Docker Hub only. A GHCR package page needs the repository, which the
     * image name does not tell.
     */
    public static function registryUrl(string $imageRef): ?string
    {
        try {
            $image = ImageRef::parse($imageRef);
        } catch (InvalidArgumentException) {
            return null;
        }

        if ($image->registry !== 'docker.io') {
            return null;
        }

        $segments = array_map('rawurlencode', explode('/', $image->repository));

        return $image->isDockerOfficial()
            ? 'https://hub.docker.com/_/'.$segments[1]
            : 'https://hub.docker.com/r/'.implode('/', $segments);
    }

    /**
     * A better base image for the final `FROM` of a Dockerfile build (spec 7.4, 15.4): of the relevant findings
     * that the current base image has too (m), how many the newer tag no longer has (n). Both are counted on the
     * current findings and ignores, with the IDs stored by the last check. Null without a better line candidate that
     * fixes one; failures of the base check are not shown.
     *
     * @return array{from: string, to: string, fixed: int, total: int}|null
     */
    public static function baseImage(ImageScan $scan): ?array
    {
        $better = self::betterBase($scan);
        $owner = $scan->resource;
        if ($better === null || ! $owner instanceof Model) {
            return null;
        }

        $base = (array) data_get($scan->candidates, 'base');
        $from = $better['from'];
        $attributableIds = array_flip(array_map(fn ($id): string => mb_strtolower((string) $id), (array) ($base['attributable_ids'] ?? [])));
        $fixedIds = array_flip(array_map(fn ($id): string => mb_strtolower((string) $id), (array) ($better['entry']['fixed_ids'] ?? [])));
        $has = fn (Finding $finding, array $ids): bool => collect([$finding->id, ...$finding->aliases])->contains(fn (string $id): bool => isset($ids[mb_strtolower($id)]));
        $attributable = array_filter(RefreshCandidates::relevantFindings($scan, $owner), fn (Finding $finding): bool => $has($finding, $attributableIds));
        $total = count($attributable);
        $fixed = count(array_filter($attributable, fn (Finding $finding): bool => $has($finding, $fixedIds)));
        if ($total === 0 || $fixed === 0) {
            return null;
        }

        return [
            'from' => $from,
            'to' => $better['to'],
            'fixed' => $fixed,
            'total' => $total,
        ];
    }

    /**
     * The better `line` candidate of the base check, when the check is for the current base image (`base_image_ref`)
     * and the candidate is a newer tag of the same image. `to` keeps the registry and name as the Dockerfile writes them.
     *
     * @return array{from: string, to: string, entry: array<string, mixed>}|null
     */
    public static function betterBase(ImageScan $scan): ?array
    {
        $from = (string) $scan->base_image_ref;
        $base = data_get($scan->candidates, 'base');
        if ($from === '' || ! is_array($base) || ($base['status'] ?? null) !== 'ok' || ($base['image'] ?? null) !== $from) {
            return null;
        }

        $entry = collect(is_array($base['entries'] ?? null) ? $base['entries'] : [])
            ->first(fn ($entry): bool => is_array($entry) && ($entry['kind'] ?? null) === 'line' && ($entry['better'] ?? false) === true && is_string($entry['ref'] ?? null));
        if ($entry === null) {
            return null;
        }

        try {
            $current = ImageRef::parse($from);
            $candidate = ImageRef::parse($entry['ref']);
        } catch (InvalidArgumentException) {
            return null;
        }
        if ($current->tag === null || $current->digest !== null || $candidate->tag === null || $candidate->tag === $current->tag
            || $candidate->qualifiedName() !== $current->qualifiedName()) {
            return null;
        }

        return [
            'from' => $from,
            'to' => substr($from, 0, (int) strrpos($from, ':')).':'.$candidate->tag,
            'entry' => $entry,
        ];
    }

    /**
     * @return array{key: string, image: string, owner: string, update: ?array<string, mixed>, message: ?string, registryUrl: ?string, loginUrl: ?string, major: ?string, base: ?array{from: string, to: string, fixed: int, total: int}}
     */
    private static function item(Model $resource, Model $owner, ImageScan $scan, User $user): array
    {
        $imageRef = (string) $scan->image_ref;
        $status = $scan->candidateStatus();
        $level = ImageScanPolicyResolver::for($owner, $scan->server)->alertLevel;
        $blocked = UpdateResourceImage::blockedReason($scan, $owner);
        $candidate = $scan->updateCandidates()[0] ?? null;
        $eol = data_get($scan->summary, 'eol_distro');
        $major = collect($scan->candidateEntries())->first(fn (array $entry): bool => ($entry['kind'] ?? null) === 'major' && is_string($entry['ref'] ?? null));
        $server = $scan->server;

        return [
            'key' => (string) $scan->uuid,
            'image' => $imageRef,
            'owner' => $resource instanceof Service ? (string) $owner->getAttribute('uuid') : '',
            'update' => $candidate === null || $blocked !== null ? null : self::update($resource, $imageRef, $candidate, $level, is_array($eol) ? $eol : null),
            'message' => match (true) {
                $candidate !== null => $blocked,
                in_array($status, ['no_better', 'cannot_compare', 'auth_required', 'failed'], true) => RefreshCandidates::message($scan),
                default => null,
            },
            'registryUrl' => $candidate === null && $status === 'cannot_compare' ? self::registryUrl($imageRef) : null,
            'loginUrl' => $candidate === null && $status === 'auth_required' && $server instanceof Server && $user->can('update', $server)
                ? route('server.registries', ['server_uuid' => $server->uuid])
                : null,
            'major' => $major === null ? null : (string) $major['ref'],
            'base' => self::baseImage($scan),
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>|null  $currentEol  The end-of-life distro of the current image.
     * @return array<string, mixed>
     */
    private static function update(Model $resource, string $imageRef, array $candidate, ImageScanAlertLevel $level, ?array $currentEol): array
    {
        $ref = (string) $candidate['ref'];
        $tag = (string) ImageRef::parse($ref)->tag;
        $sameTag = $candidate['kind'] === 'same_tag';
        $fixes = self::fixesPhrase(is_array($candidate['fixes'] ?? null) ? $candidate['fixes'] : null, $level);
        $leavesEol = filled($currentEol['name'] ?? null) && empty($candidate['eol']);
        $eolSentence = $leavesEol
            ? ' It moves off '.ucfirst((string) $currentEol['name']).rtrim(' '.($currentEol['version'] ?? '')).', which no longer gets security fixes.'
            : '';

        return [
            'ref' => $ref,
            'sameTag' => $sameTag,
            'text' => ($sameTag ? "A newer build of {$imageRef} {$fixes}" : "Update to {$tag}: {$fixes}")
                .' '.self::leftSentence(is_array($candidate['left'] ?? null) ? $candidate['left'] : null, $level)
                .$eolSentence,
            'button' => $sameTag ? 'Pull latest and restart' : "Update to {$tag}",
            'title' => $sameTag ? "Pull the newest build of {$imageRef}?" : "Update to {$tag}?",
            'actions' => self::actions($resource, $imageRef, $ref, $sameTag),
            'warning' => match (true) {
                $sameTag => "Coolify pulls the newest build of {$imageRef} and restarts.",
                $resource instanceof Service => 'One-click services pin versions that work together. Check the service template before you update.',
                $resource instanceof Application => 'Coolify redeploys the app with the new image.',
                default => 'Check the release notes. Some updates need a data migration.',
            },
        ];
    }

    /**
     * The confirmation list: what changes (`old → new`) and what restarts.
     *
     * @return list<string>
     */
    private static function actions(Model $resource, string $imageRef, string $newRef, bool $sameTag): array
    {
        $restart = match (true) {
            $resource instanceof Application => 'Redeploy the app.',
            $resource instanceof Service => 'Pull the images and restart the service.',
            default => 'Restart the database.',
        };

        if ($sameTag) {
            return ["Pull the newest build of {$imageRef}.", $restart];
        }

        if (! $resource instanceof Service) {
            return ["{$imageRef} → {$newRef}", $restart];
        }

        $actions = UpdateResourceImage::subResourcesUsing($resource, $imageRef)
            ->map(fn (Model $subResource): string => ($subResource->getAttribute('human_name') ?: $subResource->getAttribute('name')).": {$imageRef} → {$newRef}")
            ->all();

        return [...$actions, $restart];
    }
}
