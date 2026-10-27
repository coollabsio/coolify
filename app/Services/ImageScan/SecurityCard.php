<?php

namespace App\Services\ImageScan;

use App\Actions\ImageScan\UpdateResourceImage;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\ImageScanStatus;
use App\Enums\SecurityActionType;
use App\Enums\SecurityStatusType;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ImageScan;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * What the main card of the Security page shows (spec 15.7): status badge, sentence, primary action with its button
 * or copy text, why-list, and the other actions. Buttons are only offered when the user may run them; the Livewire
 * actions authorize again.
 *
 * @phpstan-type ButtonData array{label: string, title: string, submit: string, actions: list<string>, warning: string}
 * @phpstan-type ActionData array{key: string, type: string, sentence: string, copy: ?string, button: ?ButtonData}
 */
final class SecurityCard
{
    public const OTHER_ACTIONS_LIMIT = 5;

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
     * @return array{status: string, badge: ?string, badgeType: string, headline: string, lines: list<string>, primary: ?ActionData, why: list<array{title: string, display_id: string, link: ?string, score: string, signal: ?string}>, whyMore: string, others: list<ActionData>, moreActions: int}
     */
    public static function for(Model $resource, ResourceSecurity $security, Collection $owners, User $user): array
    {
        $status = $security->status;
        $showsActions = in_array($status, [SecurityStatusType::UpdateNow, SecurityStatusType::UpdateAvailable], true);
        $actions = [];
        if ($showsActions) {
            foreach ($security->actions as $index => $action) {
                $actions[] = self::action($resource, $action, (array) data_get($security->facts, "action_stats.{$index}", []), $owners, $user);
            }
        }
        $others = array_slice($actions, 1);

        return [
            'status' => $status->value,
            'badge' => SecuritySentences::badge($status),
            'badgeType' => match ($status) {
                SecurityStatusType::UpdateNow => 'error',
                SecurityStatusType::UpdateAvailable => 'warning',
                default => 'neutral',
            },
            'headline' => SecuritySentences::headline($security),
            'lines' => [...SecuritySentences::lines($security), ...self::eolLine($security)],
            'primary' => $actions[0] ?? null,
            'why' => in_array($status, [SecurityStatusType::UpdateNow, SecurityStatusType::UpdateAvailable, SecurityStatusType::NoFix], true)
                ? array_map(fn (array $entry): array => [
                    'title' => $entry['title'],
                    'display_id' => $entry['display_id'],
                    'link' => $entry['link'],
                    'score' => $entry['score'] === null ? 'No score' : number_format($entry['score'], 1).' '.ucfirst($entry['severity']),
                    'signal' => match ($entry['signal']) {
                        SecurityRules::EXPLOITED => 'Used by attackers',
                        SecurityRules::LIKELY => 'Likely to be exploited',
                        default => null,
                    },
                ], $security->why)
                : [],
            'whyMore' => $showsActions ? SecuritySentences::whyMore($security->moreFixed) : '',
            'others' => array_slice($others, 0, self::OTHER_ACTIONS_LIMIT),
            'moreActions' => max(0, count($others) - self::OTHER_ACTIONS_LIMIT),
        ];
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
     * @return ActionData
     */
    private static function action(Model $resource, SecurityAction $action, array $stats, Collection $owners, User $user): array
    {
        return [
            'key' => self::key($action),
            'type' => $action->type->value,
            'sentence' => $action->sentence,
            'copy' => $action->copy,
            'button' => $action->button && (int) $resource->getKey() !== 0 ? self::button($resource, $action, $stats, $owners, $user) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $stats
     * @param  Collection<int, Model>  $owners
     * @return ButtonData|null
     */
    private static function button(Model $resource, SecurityAction $action, array $stats, Collection $owners, User $user): ?array
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
            return self::mergedButton($resource, $action, $owners);
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
        $restart = self::restartLine($resource);

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
                ->map(fn (Model $subResource): string => self::ownerName($subResource).": {$image} → {$ref}")
                ->all()
            : ["{$image} → {$ref}"];

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
     * @return ButtonData|null
     */
    private static function mergedButton(Model $resource, SecurityAction $action, Collection $owners): ?array
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
                $changes[$subResource->getMorphClass().':'.$subResource->getKey()] = self::ownerName($subResource).": {$image} → {$ref}";
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
            'actions' => [...array_values($changes), self::restartLine($resource)],
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

    private static function restartLine(Model $resource): string
    {
        return match (true) {
            $resource instanceof Application => 'Redeploy the app.',
            $resource instanceof Service => 'Pull the images and restart the service.',
            default => 'Restart the database.',
        };
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
