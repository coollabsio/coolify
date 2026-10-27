<?php

namespace App\Services\ImageScan;

use App\Actions\ImageScan\UpdateResourceImage;
use App\Enums\ImageScanStatus;
use App\Enums\SecurityActionType;
use App\Models\Application;
use App\Models\ImageScan;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Maps each relevant finding of a scanned image to the one action that fixes it, or to none (spec 15.5).
 *
 * Reads only stored data: the findings, the version and base checks in `candidates`, and, for a Dockerfile app whose
 * deployment rebuilt without cache, one query for the previous scan of the app. A finding that is still there after a
 * rebuild without cache gets `upgrade_in_dockerfile` instead of another `rebuild`.
 */
final class SecurityActions
{
    public const BUILDER_TEXT = 'A fix exists, but it reaches this app only with a builder update in a Coolify release. To choose the base image yourself, use the Dockerfile build pack.';

    public const COOLIFY_TEXT = 'Update Coolify to get a newer version.';

    private const OS_PACKAGE_TYPES = ['apk', 'deb', 'rpm'];

    private const LANGUAGE_PACKAGE_TYPES = ['npm', 'python', 'go-module', 'java-archive', 'gem', 'php-composer', 'rust-crate', 'dotnet'];

    private const BUTTON_TYPES = [SecurityActionType::UpdateImage, SecurityActionType::PullLatest, SecurityActionType::Rebuild];

    /**
     * `byFinding` is keyed by finding ID. `eol` is the action that moves the image off an end-of-life OS.
     *
     * @return array{actions: list<SecurityAction>, byFinding: array<string, SecurityAction>, eol: ?SecurityAction}
     */
    public static function forScan(ImageScan $scan, IgnoreMatcher $ignores): array
    {
        $owner = $scan->resource;
        if (! $owner instanceof Model) {
            return ['actions' => [], 'byFinding' => [], 'eol' => null];
        }

        $kind = self::imageKind($scan, $owner);
        [$line, $sameTag] = in_array($kind, ['third_party', 'docker_image'], true) ? self::imageCandidates($scan) : [null, null];
        $base = $kind === 'dockerfile' ? FixPanel::betterBase($scan) : null;
        // Null while Coolify does not know which findings come from the base image of a Dockerfile app.
        $fromBase = $kind === 'dockerfile' ? self::baseIds($scan) : [];
        $originKnown = $fromBase !== null;
        $fromBase ??= [];
        $lineFixed = self::idSet((array) ($line['fixed_ids'] ?? []));
        $sameTagFixed = self::idSet((array) ($sameTag['fixed_ids'] ?? []));
        $baseFixed = self::idSet((array) ($base['entry']['fixed_ids'] ?? []));
        $beforeRebuild = null;

        /** @var array<string, array{type: SecurityActionType, ids: list<string>, package: ?string, fixedIn: ?string, versions: list<string>}> $plans */
        $plans = [];
        /** @var array<string, string> $byFinding */
        $byFinding = [];
        foreach (ResourceScans::findings($scan) as $finding) {
            if (! SecurityRules::isRelevant($finding, $ignores)) {
                continue;
            }

            $ids = self::ids($finding);
            $type = strtolower($finding->type);
            $hasFix = $finding->fixState === 'fixed' && filled($finding->fixedIn);
            $isOs = in_array($type, self::OS_PACKAGE_TYPES, true);
            $isFromBase = self::hits($ids, $fromBase);
            $isPackageFix = $hasFix && $originKnown && ! $isFromBase && in_array($type, self::LANGUAGE_PACKAGE_TYPES, true);

            $action = match (true) {
                self::hits($ids, $lineFixed) => SecurityActionType::UpdateImage,
                self::hits($ids, $sameTagFixed) => SecurityActionType::PullLatest,
                $isFromBase && self::hits($ids, $baseFixed) => SecurityActionType::ChangeBase,
                $kind === 'dockerfile' && $isOs && $hasFix && $originKnown && ! $isFromBase => self::hits($ids, $beforeRebuild ??= self::idsBeforeForcedRebuild($scan, $owner))
                    ? SecurityActionType::UpgradeInDockerfile
                    : SecurityActionType::Rebuild,
                $kind !== 'third_party' && $isPackageFix => SecurityActionType::UpgradePackage,
                $kind === 'docker_image' && $isOs && $hasFix => SecurityActionType::RebuildInCi,
                default => null,
            };
            if ($action === null) {
                continue;
            }

            $perPackage = in_array($action, [SecurityActionType::UpgradePackage, SecurityActionType::UpgradeInDockerfile], true);
            $key = $action->value.($perPackage ? ':'.$type.'|'.$finding->package : '');
            $plans[$key] ??= ['type' => $action, 'ids' => [], 'package' => $perPackage ? $finding->package : null, 'fixedIn' => null, 'versions' => []];
            if (! in_array($finding->id, $plans[$key]['ids'], true)) {
                $plans[$key]['ids'][] = $finding->id;
            }
            if ($perPackage) {
                $plans[$key]['fixedIn'] = self::higher($plans[$key]['fixedIn'], (string) $finding->fixedIn);
                $plans[$key]['versions'][] = $finding->version;
            }
            $byFinding[$finding->id] ??= $key;
        }

        $eolKey = null;
        if (is_array(data_get($scan->summary, 'eol_distro'))) {
            $eolType = match (true) {
                $line !== null && empty($line['eol']) => SecurityActionType::UpdateImage,
                $sameTag !== null && empty($sameTag['eol']) => SecurityActionType::PullLatest,
                $base !== null && empty($base['entry']['eol']) => SecurityActionType::ChangeBase,
                default => null,
            };
            if ($eolType !== null) {
                $eolKey = $eolType->value;
                $plans[$eolKey] ??= ['type' => $eolType, 'ids' => [], 'package' => null, 'fixedIn' => null, 'versions' => []];
            }
        }

        $order = array_flip(array_map(fn (SecurityActionType $type): string => $type->value, SecurityActionType::cases()));
        uasort($plans, fn (array $a, array $b): int => $order[$a['type']->value] <=> $order[$b['type']->value]);

        $context = [
            'image' => $scan->image_ref === null ? null : (string) $scan->image_ref,
            'coolify' => self::isCoolifyResource($owner),
            // A Compose app sets the images of its file; Coolify does not change them.
            'compose' => $owner instanceof Application && $owner->build_pack === 'dockercompose',
            'buttons' => ! self::isCoolifyResource($owner) && ! (bool) $scan->server?->isTransferredAway(),
            'switchBlocked' => $line !== null || $sameTag !== null ? UpdateResourceImage::blockedReason($scan, $owner) !== null : false,
            'line' => $line,
            'sameTag' => $sameTag,
            'base' => $base,
        ];
        $actions = array_map(fn (array $plan): SecurityAction => self::action($plan, $context), $plans);

        return [
            'actions' => array_values($actions),
            'byFinding' => array_map(fn (string $key): SecurityAction => $actions[$key], $byFinding),
            'eol' => $eolKey === null ? null : $actions[$eolKey],
        ];
    }

    /**
     * @param  array{type: SecurityActionType, ids: list<string>, package: ?string, fixedIn: ?string, versions: list<string>}  $plan
     * @param  array{image: ?string, coolify: bool, compose: bool, buttons: bool, switchBlocked: bool, line: ?array<string, mixed>, sameTag: ?array<string, mixed>, base: ?array{from: string, to: string, entry: array<string, mixed>}}  $context
     */
    private static function action(array $plan, array $context): SecurityAction
    {
        $type = $plan['type'];
        $package = (string) $plan['package'];
        $fixedIn = (string) $plan['fixedIn'];
        $candidate = match ($type) {
            SecurityActionType::UpdateImage => $context['line'],
            SecurityActionType::PullLatest => $context['sameTag'],
            default => null,
        };
        $ref = $candidate === null ? null : (string) $candidate['ref'];
        $tag = $ref === null ? null : ImageRef::parse($ref)->tag;
        $newBase = $type === SecurityActionType::ChangeBase ? (string) $context['base']['to'] : null;

        $sentence = match ($type) {
            SecurityActionType::UpdateImage => match (true) {
                $context['coolify'] => self::COOLIFY_TEXT,
                $context['compose'] => "Change the version to {$tag} in the compose file of this app, then redeploy.",
                default => "Update to {$tag}.",
            },
            SecurityActionType::PullLatest => match (true) {
                $context['coolify'] => self::COOLIFY_TEXT,
                $context['compose'] => 'A newer build of this image fixes it. Redeploy this app to pull it.',
                default => 'A newer build of this image fixes it. Pull it and restart.',
            },
            SecurityActionType::ChangeBase => "Change the base image to {$newBase} in your Dockerfile, then redeploy.",
            SecurityActionType::Rebuild => 'Rebuild without cache to get the fixed system packages.',
            SecurityActionType::UpgradeInDockerfile => "Upgrade {$package} to {$fixedIn} or later in your Dockerfile.",
            SecurityActionType::UpgradePackage => "Upgrade {$package} to {$fixedIn} or later, then redeploy."
                .(self::changesMajor($plan['versions'], $fixedIn) ? ' This is a major version change. Check for breaking changes.' : ''),
            SecurityActionType::RebuildInCi => 'Rebuild the image in your CI, then redeploy.',
        };

        return new SecurityAction(
            type: $type,
            sentence: $sentence,
            target: match ($type) {
                SecurityActionType::UpdateImage, SecurityActionType::PullLatest => $tag,
                SecurityActionType::ChangeBase => $newBase,
                SecurityActionType::UpgradePackage, SecurityActionType::UpgradeInDockerfile => $package,
                default => null,
            },
            copy: match ($type) {
                SecurityActionType::ChangeBase => "FROM {$newBase}",
                SecurityActionType::UpgradePackage => "{$package} {$fixedIn}",
                default => null,
            },
            fixedIds: $plan['ids'],
            button: in_array($type, self::BUTTON_TYPES, true) && $context['buttons']
                && ($type === SecurityActionType::Rebuild || (! $context['switchBlocked'] && ! $context['compose'])),
            image: $context['image'],
            ref: $ref,
        );
    }

    /**
     * third_party: Coolify pulls it. docker_image: a Docker image app the team builds itself. dockerfile, compose,
     * builder (Nixpacks, Railpack, static): Coolify builds it.
     */
    private static function imageKind(ImageScan $scan, Model $owner): string
    {
        if (! (bool) $scan->builds_image || ! $owner instanceof Application) {
            return 'third_party';
        }

        return match ($owner->build_pack) {
            'dockerimage' => 'docker_image',
            'dockerfile' => 'dockerfile',
            'dockercompose' => 'compose',
            default => 'builder',
        };
    }

    /**
     * The better newer tag of the same line and the better newer build of the same tag. Never a major version.
     *
     * @return array{0: ?array<string, mixed>, 1: ?array<string, mixed>}
     */
    private static function imageCandidates(ImageScan $scan): array
    {
        $candidates = collect($scan->updateCandidates());

        return [
            $candidates->first(fn (array $entry): bool => $entry['kind'] === 'line'),
            $candidates->first(fn (array $entry): bool => $entry['kind'] === 'same_tag'),
        ];
    }

    /**
     * The relevant findings that the current base image has too. Empty without a base image. Null while unknown: the
     * base check did not run yet, is for another base image, or did not scan the current base image (failed,
     * auth_required).
     *
     * @return array<string, true>|null
     */
    private static function baseIds(ImageScan $scan): ?array
    {
        if (blank($scan->base_image_ref)) {
            return [];
        }

        $base = data_get($scan->candidates, 'base');
        if (! is_array($base) || ($base['image'] ?? null) !== $scan->base_image_ref || ! is_int($base['attributable'] ?? null)) {
            return null;
        }

        return self::idSet((array) ($base['attributable_ids'] ?? []));
    }

    /**
     * The IDs of the previous completed scan of the app that is not a preview, when the deployment of this scan rebuilt
     * without cache. Empty otherwise.
     *
     * @return array<string, true>
     */
    private static function idsBeforeForcedRebuild(ImageScan $scan, Model $owner): array
    {
        if (! $owner instanceof Application || ! $scan->exists || $scan->deployment_queue_id === null
            || ! (bool) $scan->deploymentQueue?->force_rebuild) {
            return [];
        }

        $previous = ImageScan::query()
            ->select(['id', 'findings'])
            ->where('resource_type', $owner->getMorphClass())
            ->where('resource_id', $owner->getKey())
            ->where('status', ImageScanStatus::Completed)
            ->where('id', '<', $scan->getKey())
            ->where(fn (Builder $query) => $query
                ->whereNull('deployment_queue_id')
                ->orWhereHas('deploymentQueue', fn (Builder $query) => $query->where('pull_request_id', 0)))
            ->orderByDesc('id')
            ->first();
        if ($previous === null) {
            return [];
        }

        $ids = [];
        foreach (ResourceScans::findings($previous) as $finding) {
            $ids = [...$ids, ...self::ids($finding)];
        }

        return self::idSet($ids);
    }

    private static function isCoolifyResource(Model $owner): bool
    {
        if ($owner->getKey() !== null && (int) $owner->getKey() === 0) {
            return true;
        }

        if (! $owner instanceof ServiceApplication && ! $owner instanceof ServiceDatabase) {
            return false;
        }
        $serviceId = $owner->relationLoaded('service') ? $owner->service?->getKey() : $owner->getAttribute('service_id');

        return $serviceId !== null && (int) $serviceId === 0;
    }

    /**
     * The higher of two fixed versions (a leading `v` is ignored). A version is preferred to a value that is not one;
     * of two values that are not versions, the first is kept.
     */
    private static function higher(?string $current, string $candidate): string
    {
        if ($current === null) {
            return $candidate;
        }

        $a = preg_replace('/^v/i', '', $current);
        $b = preg_replace('/^v/i', '', $candidate);
        $aIsVersion = preg_match('/^\d/', $a) === 1;
        $bIsVersion = preg_match('/^\d/', $b) === 1;
        if (! $aIsVersion || ! $bIsVersion) {
            return $bIsVersion ? $candidate : $current;
        }

        return version_compare($b, $a, '>') ? $candidate : $current;
    }

    /**
     * @param  list<string>  $versions
     */
    private static function changesMajor(array $versions, string $fixedIn): bool
    {
        $target = self::major($fixedIn);

        return $target !== null && collect($versions)->contains(fn (string $version): bool => ($major = self::major($version)) !== null && $major !== $target);
    }

    private static function major(string $version): ?int
    {
        return preg_match('/^v?(\d+)/i', $version, $match) === 1 ? (int) $match[1] : null;
    }

    /**
     * @return list<string>
     */
    private static function ids(Finding $finding): array
    {
        return array_values(array_filter(array_map('mb_strtolower', [$finding->id, ...$finding->aliases]), fn (string $id): bool => $id !== ''));
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<string, true>
     */
    private static function idSet(array $ids): array
    {
        $set = [];
        foreach ($ids as $id) {
            if (is_string($id) && $id !== '') {
                $set[mb_strtolower($id)] = true;
            }
        }

        return $set;
    }

    /**
     * @param  list<string>  $ids
     * @param  array<string, true>  $set
     */
    private static function hits(array $ids, array $set): bool
    {
        foreach ($ids as $id) {
            if (isset($set[$id])) {
                return true;
            }
        }

        return false;
    }
}
