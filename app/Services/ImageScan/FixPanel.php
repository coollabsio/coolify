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
     * @return list<array{key: string, image: string, owner: string, update: ?array<string, mixed>, message: ?string, registryUrl: ?string, loginUrl: ?string, major: ?string}>
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
            if ($item['update'] !== null || $item['message'] !== null || $item['major'] !== null) {
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
            && $scans->contains(fn (ImageScan $scan): bool => (int) $scan->resource_id !== 0 && $scan->updateCandidates() !== []);
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
     * @return array{key: string, image: string, owner: string, update: ?array<string, mixed>, message: ?string, registryUrl: ?string, loginUrl: ?string, major: ?string}
     */
    private static function item(Model $resource, Model $owner, ImageScan $scan, User $user): array
    {
        $imageRef = (string) $scan->image_ref;
        $status = $scan->candidateStatus();
        $level = ImageScanPolicyResolver::for($owner, $scan->server)->alertLevel;
        $candidate = $scan->updateCandidates()[0] ?? null;
        $major = collect($scan->candidateEntries())->first(fn (array $entry): bool => ($entry['kind'] ?? null) === 'major' && is_string($entry['ref'] ?? null));
        $server = $scan->server;

        return [
            'key' => (string) $scan->uuid,
            'image' => $imageRef,
            'owner' => $resource instanceof Service ? (string) $owner->getAttribute('uuid') : '',
            'update' => $candidate === null ? null : self::update($resource, $imageRef, $candidate, $level),
            'message' => $candidate === null && in_array($status, ['no_better', 'cannot_compare', 'auth_required', 'failed'], true)
                ? RefreshCandidates::message($scan)
                : null,
            'registryUrl' => $candidate === null && $status === 'cannot_compare' ? self::registryUrl($imageRef) : null,
            'loginUrl' => $candidate === null && $status === 'auth_required' && $server instanceof Server && $user->can('update', $server)
                ? route('server.registries', ['server_uuid' => $server->uuid])
                : null,
            'major' => $major === null ? null : (string) $major['ref'],
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private static function update(Model $resource, string $imageRef, array $candidate, ImageScanAlertLevel $level): array
    {
        $ref = (string) $candidate['ref'];
        $tag = (string) ImageRef::parse($ref)->tag;
        $sameTag = $candidate['kind'] === 'same_tag';
        $fixes = 0;
        foreach (self::SEVERITIES as $severity) {
            $fixes += $level->includes($severity) ? (int) data_get($candidate, "fixes.{$severity}", 0) : 0;
        }
        $fixed = "{$fixes} ".self::levelWords($level);

        return [
            'ref' => $ref,
            'sameTag' => $sameTag,
            'text' => ($sameTag ? "A newer build of {$imageRef} fixes {$fixed}." : "Update to {$tag}: fixes {$fixed}.")
                .' '.self::leftSentence(is_array($candidate['left'] ?? null) ? $candidate['left'] : null, $level),
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
