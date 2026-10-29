<?php

namespace App\Services\ImageScan;

use App\Enums\SecurityActionType;
use App\Enums\SecurityStatusType;
use App\Models\ImageScan;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * The copy of the security status card (spec 15.5, 15.7, 15.11). Pure functions of the stored `ResourceSecurity` and
 * the stored scan data.
 */
final class SecuritySentences
{
    private const REGISTRY_NAMES = [
        'docker.io' => 'Docker Hub',
        'ghcr.io' => 'GitHub Container Registry',
        'quay.io' => 'Quay',
    ];

    private const CHECK_REASONS = [
        SecurityStatus::REASON_IMAGES_UNCHECKED,
        SecurityStatus::REASON_TRUNCATED,
        SecurityStatus::REASON_STALE,
        SecurityStatus::REASON_DB_OUTDATED,
        SecurityStatus::REASON_IMAGE_CHANGED,
        SecurityStatus::REASON_VERSIONS_UNCHECKED,
    ];

    /**
     * The sentence under the status line.
     */
    public static function headline(ResourceSecurity $security): string
    {
        return match ($security->status) {
            SecurityStatusType::UpdateNow => self::updateNow($security),
            SecurityStatusType::UpdateAvailable => self::updateAvailable($security),
            SecurityStatusType::NoFix => self::noFix($security),
            SecurityStatusType::Ok => 'No action needed.',
            SecurityStatusType::CheckNeeded => 'Coolify needs to check this image again: '.(in_array($security->checkReason, self::CHECK_REASONS, true)
                ? self::checkReason((string) $security->checkReason, self::checkContext($security))
                : 'the last check is incomplete').'.',
            SecurityStatusType::NotChecked => self::checkReason((string) ($security->checkReason ?? 'not_scanned'), $security->checkContext),
        };
    }

    /**
     * Extra lines under the headline.
     *
     * @return list<string>
     */
    public static function lines(ResourceSecurity $security): array
    {
        if (! $security->exploitedWithoutFix) {
            return [];
        }

        $unfixed = match ($security->status) {
            SecurityStatusType::UpdateNow => $security->exploitedCount - $security->problemCount,
            SecurityStatusType::UpdateAvailable => $security->exploitedCount,
            default => 0,
        };
        if ($unfixed < 1) {
            return [];
        }

        $use = (int) data_get($security->facts, 'counts.kev_unfixed', 0) > 0 ? 'are using' : 'are likely to use';

        return [$unfixed > 1
            ? "{$unfixed} more problems that attackers {$use} have no fix yet."
            : "One more problem that attackers {$use} has no fix yet."];
    }

    /**
     * The reason of a check_needed status, or the text of a not_checked status.
     *
     * @param  array<string, int|string|null>  $context
     */
    public static function checkReason(string $code, array $context = []): string
    {
        return match ($code) {
            SecurityStatus::REASON_IMAGES_UNCHECKED => (int) ($context['checked'] ?? 0).' of '.(int) ($context['total'] ?? 0).' images were checked',
            SecurityStatus::REASON_TRUNCATED => 'the scan result was too large to read completely',
            SecurityStatus::REASON_STALE => 'the last check was '.self::ago($context['time'] ?? null),
            SecurityStatus::REASON_DB_OUTDATED => 'the vulnerability database is out of date',
            SecurityStatus::REASON_IMAGE_CHANGED => 'the running image is not the checked image',
            SecurityStatus::REASON_VERSIONS_UNCHECKED => 'Coolify could not check for newer versions',
            'instance' => 'Image scanning is off for this Coolify instance.',
            'server' => 'Image scanning is off for this server.',
            'application' => 'Image scanning is off for this app.',
            default => 'This image has not been scanned yet.',
        };
    }

    /**
     * The line after the why-list. Empty for none.
     */
    public static function whyMore(int $count): string
    {
        if ($count <= 0) {
            return '';
        }

        return $count === 1
            ? 'and 1 more serious problem that this update fixes'
            : "and {$count} more serious problems that this update fixes";
    }

    public static function badge(SecurityStatusType $status): ?string
    {
        return match ($status) {
            SecurityStatusType::UpdateNow => 'Update now',
            SecurityStatusType::UpdateAvailable => 'Security update',
            SecurityStatusType::NoFix => 'No fix yet',
            SecurityStatusType::CheckNeeded => 'Check needed',
            SecurityStatusType::Ok, SecurityStatusType::NotChecked => null,
        };
    }

    /**
     * The confirmation before a database moves to a newer tag of the same major version.
     */
    public static function databaseUpdateConfirmation(string $dbType, string $fromTag, string $toTag): string
    {
        $major = preg_match('/^v?(\d+)/i', $toTag, $matches) === 1 ? $matches[1] : (preg_match('/^v?(\d+)/i', $fromTag, $matches) === 1 ? $matches[1] : null);
        $name = $major === null ? $dbType : "{$dbType} {$major}";

        return "This is a patch release of {$name}. Your data stays as it is. The database restarts and is unavailable for a few seconds.";
    }

    /**
     * The sentence of one image row (spec 15.11): it starts with the image ref.
     */
    public static function imageSentence(ResourceSecurity $security, string $imageRef): string
    {
        return match ($security->status) {
            SecurityStatusType::UpdateNow => self::updateNow($security, $imageRef, 'A newer build'),
            SecurityStatusType::UpdateAvailable => self::imageUpdateAvailable($security, $imageRef),
            SecurityStatusType::NoFix => $security->builderImage
                ? self::noFix($security, $imageRef)
                : "{$imageRef} has ".self::problems($security->problemCount).' that attackers '.($security->anyExploited ? 'are using' : 'are likely to use')
                    .'. No fixed version exists yet, and Coolify checks every day.',
            SecurityStatusType::Ok => "{$imageRef} needs no action.",
            SecurityStatusType::CheckNeeded => "{$imageRef} needs another check: ".(in_array($security->checkReason, self::CHECK_REASONS, true)
                ? self::checkReason((string) $security->checkReason, self::checkContext($security))
                : 'the last check is incomplete').'.',
            SecurityStatusType::NotChecked => in_array($security->checkReason, [null, SecurityStatus::NOT_SCANNED], true)
                ? "{$imageRef} has not been checked yet."
                : self::checkReason((string) $security->checkReason, $security->checkContext),
        };
    }

    /**
     * The short note after the sentence of an image row that does not mention attackers itself (update_available).
     */
    public static function attackNote(ResourceSecurity $security): ?string
    {
        if ($security->status !== SecurityStatusType::UpdateAvailable || $security->exploitedWithoutFix) {
            return null;
        }

        return 'No known attacks on '.(max(1, $security->fixedByPrimary) > 1 ? 'these problems' : 'this problem').'.';
    }

    /**
     * The advice under a no_fix image row, until a fix exists ("this app", "this service", "this database"). Null for
     * other statuses and for builder images, whose sentence says that a builder update fixes them.
     */
    public static function noFixAdvice(ResourceSecurity $security, string $resourceNoun = 'this app'): ?string
    {
        if ($security->status !== SecurityStatusType::NoFix || $security->builderImage) {
            return null;
        }

        return $security->problemCount > 1
            ? "Until then, limit who can reach {$resourceNoun}, or ignore the problems if the affected parts are not used."
            : "Until then, limit who can reach {$resourceNoun}, or ignore the problem if the affected part is not used.";
    }

    /**
     * How Coolify knows that the action of an image row fixes its problems, or that no fix exists (spec 15.11), from
     * the stored version and base checks only. Null when there is nothing to say.
     *
     * @param  array{fixes?: ?int, adds?: ?int, fixed_in?: ?string}  $counts  What the candidate fixes and adds, and the
     *                                                                        first fixed version of a package upgrade.
     */
    public static function provenance(ImageScan $scan, ?SecurityAction $action, ResourceSecurity $security, array $counts = []): ?string
    {
        if ($action === null) {
            return $security->status === SecurityStatusType::NoFix && ! $security->builderImage ? self::noFixProvenance($scan, $security) : null;
        }

        $registry = self::registryName((string) $scan->image_ref);

        switch ($action->type) {
            case SecurityActionType::PullLatest:
            case SecurityActionType::UpdateImage:
                $entry = self::candidateEntry($scan, $action);
                if ($entry === []) {
                    return null;
                }
                $tag = (string) ($action->target ?? self::tagOf((string) $action->ref));
                $line = self::releaseLine(self::tagOf((string) $scan->image_ref), $tag);
                $intro = $action->type === SecurityActionType::PullLatest
                    ? "{$registry} has a newer build of {$tag}."
                    : ($line === null
                        ? "{$tag} is the newest release of this line on {$registry}."
                        : "{$tag} is the newest {$line} release on {$registry}.");

                return "{$intro} Coolify scanned it".self::scannedAt($entry['scanned_at'] ?? null).self::candidateFindings($entry);
            case SecurityActionType::ChangeBase:
                $base = data_get($scan->candidates, 'base');
                $entry = collect(is_array($base) && is_array($base['entries'] ?? null) ? $base['entries'] : [])
                    ->first(fn ($entry): bool => is_array($entry) && ($entry['kind'] ?? null) === 'line' && ($entry['better'] ?? false) === true
                        && (string) ($entry['ref'] ?? '') !== '' && self::tagOf((string) $entry['ref']) === self::tagOf((string) $action->target));
                if (! is_array($entry)) {
                    return null;
                }

                return "{$action->target} is the newest release of your base image. Coolify scanned it".self::scannedAt($entry['scanned_at'] ?? null).self::candidateFindings($entry);
            case SecurityActionType::UpgradePackage:
            case SecurityActionType::UpgradeInDockerfile:
                $fixedIn = $counts['fixed_in'] ?? null;

                return filled($fixedIn) && filled($action->target)
                    ? "The advisory lists {$action->target} {$fixedIn} as the first fixed version."
                    : null;
            case SecurityActionType::Rebuild:
                return filled($scan->base_image_ref)
                    ? "The current {$scan->base_image_ref} image already has the fixed system packages."
                    : 'The current base image already has the fixed system packages.';
            default:
                return null;
        }
    }

    /**
     * "Docker Hub" for docker.io, "GitHub Container Registry" for ghcr.io, "Quay" for quay.io, else the host.
     */
    public static function registryName(string $imageRef): string
    {
        try {
            $registry = ImageRef::parse($imageRef)->registry;
        } catch (InvalidArgumentException) {
            return 'the registry';
        }

        return self::REGISTRY_NAMES[$registry] ?? $registry;
    }

    /**
     * The header of a list of several images: "2 of 3 images need an update". Null when none needs an update.
     */
    public static function imagesNeedingUpdate(int $count, int $total): ?string
    {
        if ($count < 1) {
            return null;
        }

        return "{$count} of {$total} images ".($count === 1 ? 'needs' : 'need').' an update';
    }

    /**
     * The count in the footer of an image row: "12 known issues".
     */
    public static function knownIssues(int $known): string
    {
        return match (true) {
            $known < 1 => 'No known issues',
            $known === 1 => '1 known issue',
            default => "{$known} known issues",
        };
    }

    /**
     * "sha256:" and the first 12 characters of the digest, without the prefix: "3f1a2b4c5d6e".
     */
    public static function shortDigest(?string $digest): ?string
    {
        $digest = trim((string) $digest);
        if ($digest === '') {
            return null;
        }

        $hex = str_contains($digest, 'sha256:') ? substr($digest, (int) strpos($digest, 'sha256:') + strlen('sha256:')) : $digest;

        return substr($hex, 0, 12) ?: null;
    }

    /**
     * "Coolify checked Docker Hub 2 hours ago. No newer 16.x release fixes this yet." Only for images Coolify checks
     * for newer versions.
     */
    private static function noFixProvenance(ImageScan $scan, ResourceSecurity $security): ?string
    {
        $checkedAt = is_array($scan->candidates) && ! array_is_list($scan->candidates) ? ($scan->candidates['checked_at'] ?? null) : null;
        if (! is_string($checkedAt) || ! in_array($scan->candidateStatus(), ['ok', 'no_better'], true)) {
            return null;
        }

        $one = $security->problemCount <= 1;
        $entries = collect($scan->candidateEntries());
        $tag = self::tagOf((string) $scan->image_ref);
        $checked = 'Coolify checked '.self::registryName((string) $scan->image_ref).self::scannedAt($checkedAt);

        $newer = $entries->filter(fn (array $entry): bool => in_array($entry['kind'] ?? null, ['same_tag', 'line'], true))
            ->map(fn (array $entry): string => ($entry['kind'] ?? null) === 'same_tag' ? "a newer build of {$tag}" : (string) self::tagOf((string) ($entry['ref'] ?? '')))
            ->unique()->values()->all();
        $line = self::releaseLine($tag, $tag);
        $sentences = [$newer === []
            ? "{$checked}: you already run the newest ".($line === null ? "build of {$tag}" : "{$line} version").'.'
            : "{$checked}: ".self::andList($newer).(count($newer) > 1 ? ' are' : ' is').' newer, but not safer.'];

        $sentences[] = self::noFixPackages($scan, $security, $one);

        $major = $entries->first(fn (array $entry): bool => ($entry['kind'] ?? null) === 'major' && array_key_exists('fixes_problems', $entry));
        if (is_array($major)) {
            $fixed = array_flip(array_map(fn ($id): string => mb_strtolower((string) $id), (array) ($major['fixed_ids'] ?? [])));
            $whyIds = array_map(fn (array $why): string => mb_strtolower((string) $why['display_id']), $security->why);
            $fixedWhy = count(array_filter($whyIds, fn (string $id): bool => isset($fixed[$id])));
            $ref = (string) ($major['ref'] ?? '');
            $sentences[] = ($major['fixes_problems'] ?? false) === true && $fixedWhy > 0
                ? "{$ref}, a new major version, fixes ".($fixedWhy < count($whyIds) ? 'some of them' : ($one ? 'it' : 'them'))
                    .'. Coolify does not change major versions for you, because they can need a data migration or code changes: read its upgrade notes first.'
                : "The newest major version, {$ref}, is not safer either.";
        }

        return implode(' ', array_filter($sentences));
    }

    /**
     * Where the problems of a no_fix image are, and whether a fixed version of those packages exists upstream that the
     * image does not include yet. Null without known packages.
     */
    private static function noFixPackages(ImageScan $scan, ResourceSecurity $security, bool $one): ?string
    {
        $packages = array_values(array_unique(array_filter(array_map(fn (array $why): string => (string) $why['package'], $security->why))));
        if ($packages === []) {
            return null;
        }

        $whyIds = array_flip(array_map(fn (array $why): string => mb_strtolower((string) $why['display_id']), $security->why));
        $findings = array_filter(ResourceScans::findings($scan), fn (Finding $finding): bool => array_intersect_key(
            array_flip(array_map('mb_strtolower', [$finding->id, $finding->displayId, ...$finding->aliases])), $whyIds) !== []);
        $withFix = count(array_filter($findings, fn (Finding $finding): bool => $finding->fixState === 'fixed' && filled($finding->fixedIn)));
        $names = self::andList($packages);
        $where = ($security->problemCount > count($security->why) ? 'The listed problems are' : ($one ? 'The problem is' : 'The problems are'))." in {$names}";

        return match (true) {
            $withFix === 0 => "{$where}, and no fixed version of ".(count($packages) > 1 ? 'them' : 'it').' exists yet.',
            $withFix === count($findings) => "{$where}. Fixed versions exist, but the image does not include them yet.",
            default => "{$where}. Some of them have a fixed version that the image does not include yet.",
        };
    }

    /**
     * "8.x" for the line of a tag, or "8.0.x" when both tags have three or more version parts with the same minor (a
     * patch of that minor). Null for a tag that is not a version.
     */
    private static function releaseLine(?string $currentTag, ?string $tag): ?string
    {
        $parts = fn (?string $value): array => $value !== null && preg_match('/^[^0-9]*(\d+(?:\.\d+)*)/', $value, $match) === 1 ? explode('.', $match[1]) : [];
        [$current, $candidate] = [$parts($currentTag), $parts($tag)];
        if ($candidate === []) {
            return null;
        }
        if (count($candidate) >= 3 && count($current) >= 3 && $current[0] === $candidate[0] && $current[1] === $candidate[1] && $currentTag !== $tag) {
            return "{$candidate[0]}.{$candidate[1]}.x";
        }

        return "{$candidate[0]}.x";
    }

    /**
     * "a", "a and b", "a, b, and c".
     *
     * @param  list<string>  $items
     */
    private static function andList(array $items): string
    {
        return count($items) < 3 ? implode(' and ', $items) : implode(', ', array_slice($items, 0, -1)).', and '.end($items);
    }

    /**
     * What the scan of an offered candidate found, without a count of its own, so it cannot contradict the sentence:
     * ": it fixes these problems and adds none that attackers use." The "better" rule (spec 15.4) guarantees that an
     * offered candidate adds no finding with an exploit signal. The other relevant findings it adds (`added`, unique
     * IDs) follow as their own sentence; none when the entry does not store them.
     *
     * @param  array<string, mixed>  $entry
     */
    private static function candidateFindings(array $entry): string
    {
        $text = ': it fixes these problems and adds none that attackers use.';
        $added = $entry['added'] ?? null;
        if (is_int($added) && $added > 0) {
            $text .= $added === 1
                ? ' It has 1 other problem that attackers are not known to use.'
                : " It has {$added} other problems that attackers are not known to use.";
        }

        return $text;
    }

    /**
     * The stored candidate of an update_image or pull_latest action.
     *
     * @return array<string, mixed>
     */
    private static function candidateEntry(ImageScan $scan, SecurityAction $action): array
    {
        $kind = $action->type === SecurityActionType::PullLatest ? 'same_tag' : 'line';

        return collect($scan->updateCandidates())
            ->first(fn (array $entry): bool => ($entry['kind'] ?? null) === $kind && ($action->ref === null || ($entry['ref'] ?? null) === $action->ref)) ?? [];
    }

    /**
     * " 2 hours ago", or nothing when the time is not known.
     */
    private static function scannedAt(mixed $time): string
    {
        return is_string($time) && $time !== '' ? ' '.self::ago($time) : '';
    }

    private static function tagOf(string $ref): ?string
    {
        try {
            return ImageRef::parse($ref)->tag;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private static function major(?string $tag): ?string
    {
        return $tag !== null && preg_match('/^v?(\d+)[.\-]/i', $tag.'.', $matches) === 1 ? $matches[1] : null;
    }

    /**
     * update_available in the words of an image row: "postgres:16-alpine has a newer build that fixes 2 critical
     * problems." or "{ref} has a serious problem. Update to 16.4-alpine to fix it."
     */
    private static function imageUpdateAvailable(ResourceSecurity $security, string $imageRef): string
    {
        $action = $security->primary();
        $fixed = max(1, $security->fixedByPrimary);
        $onlyCritical = $security->primaryCritical > 0 && $security->primaryHigh === 0;
        $parts = array_values(array_filter([
            $security->primaryCritical > 0 ? "{$security->primaryCritical} critical" : null,
            $security->primaryHigh > 0 ? "{$security->primaryHigh} high" : null,
        ]));
        $problems = match (true) {
            $fixed === 1 => $onlyCritical ? 'a critical problem' : 'a serious problem',
            $onlyCritical => "{$fixed} critical problems",
            default => "{$fixed} serious problems".($parts === [] ? '' : ' ('.implode(', ', $parts).')'),
        };
        $it = $fixed === 1 ? 'it' : 'them';

        $sentence = (string) $action?->sentence;
        $majorNote = ' This is a major version change. Check for breaking changes.';
        $note = '';
        if (str_ends_with($sentence, $majorNote)) {
            $sentence = substr($sentence, 0, -strlen($majorNote));
            $note = $majorNote;
        }

        $text = match (true) {
            $action === null => "{$imageRef} has {$problems}.",
            $action->type === SecurityActionType::PullLatest && $sentence !== SecurityActions::COOLIFY_TEXT => "{$imageRef} has a newer build that fixes {$problems}."
                .(str_contains($sentence, 'Redeploy this app to pull it.') ? ' Redeploy this app to pull it.' : ''),
            $action->type === SecurityActionType::UpdateImage && str_starts_with($sentence, 'Update to ') && str_ends_with($sentence, '.') => "{$imageRef} has {$problems}. ".substr($sentence, 0, -1)." to fix {$it}.",
            default => "{$imageRef} has {$problems}. {$sentence}",
        };
        $eol = self::eolSentence($security);

        return trim($text.$note.($eol === '' ? '' : " {$eol}"));
    }

    private static function updateNow(ResourceSecurity $security, string $subject = 'This image', string $newerBuild = 'A newer build of this image'): string
    {
        $problems = self::problems($security->problemCount);
        $use = $security->anyExploited ? 'are using' : 'are likely to use';
        $headline = "{$subject} has {$problems} that attackers {$use}.";

        $action = $security->primary();
        if ($action === null) {
            return $headline;
        }

        $fixed = $security->fixedByPrimary;
        $what = $fixed > 0 && $fixed < $security->problemCount ? "{$fixed} of them" : ($security->problemCount === 1 ? 'it' : 'them');
        $sentence = $action->sentence;
        if ($action->type === SecurityActionType::UpdateImage && str_starts_with($sentence, 'Update ') && $sentence !== SecurityActions::COOLIFY_TEXT && str_ends_with($sentence, '.')) {
            $sentence = substr($sentence, 0, -1)." to fix {$what}.";
        } elseif ($action->type === SecurityActionType::PullLatest && $sentence !== SecurityActions::COOLIFY_TEXT) {
            $sentence = "{$newerBuild} fixes {$what}. ".self::pullInstruction($sentence);
        }

        return trim("{$headline} {$sentence} ".self::eolSentence($security));
    }

    private static function updateAvailable(ResourceSecurity $security): string
    {
        $action = $security->primary();
        $fixed = max(1, $security->fixedByPrimary);
        $parts = array_values(array_filter([
            $security->primaryCritical > 0 ? "{$security->primaryCritical} critical" : null,
            $security->primaryHigh > 0 ? "{$security->primaryHigh} high" : null,
        ]));
        $breakdown = $parts === [] ? '' : ' ('.implode(', ', $parts).')';
        $noun = $fixed === 1 ? 'problem' : 'problems';
        $fixes = "This fixes {$fixed} serious {$noun}{$breakdown}.";

        $sentence = $action?->sentence ?? '';
        if ($action?->type === SecurityActionType::PullLatest && $sentence !== SecurityActions::COOLIFY_TEXT) {
            $sentence = 'A newer build of this image is available. '.self::pullInstruction($sentence);
        }
        $note = '';
        $majorNote = ' This is a major version change. Check for breaking changes.';
        if (str_ends_with($sentence, $majorNote)) {
            $sentence = substr($sentence, 0, -strlen($majorNote));
            $note = $majorNote;
        }

        $text = trim("{$sentence} {$fixes}").$note;
        $eol = self::eolSentence($security);
        if ($eol !== '') {
            $text .= " {$eol}";
        }
        if (! $security->exploitedWithoutFix) {
            $text .= ' Attackers are not known to use '.($fixed === 1 ? 'it' : 'them').'.';
        }

        return $text;
    }

    private static function noFix(ResourceSecurity $security, string $subject = 'This image'): string
    {
        $problems = self::problems($security->problemCount);
        $use = $security->anyExploited ? 'are using' : 'are likely to use';

        if ($security->builderImage) {
            return "{$subject} has {$problems} that attackers {$use}. ".SecurityActions::BUILDER_TEXT;
        }

        $many = $security->problemCount > 1;
        $ignore = $many ? 'ignore the problems if the affected parts are not used' : 'ignore the problem if the affected part is not used';

        return "{$subject} has {$problems} that attackers {$use}, and no fixed version exists yet. Coolify checks every day and tells you when a fix is out. Until then, limit who can reach this app, or {$ignore}.";
    }

    /**
     * The "pull it" half of a pull_latest sentence: "Pull it and restart." or "Redeploy this app to pull it.".
     */
    private static function pullInstruction(string $sentence): string
    {
        return str_contains($sentence, 'Redeploy this app to pull it.') ? 'Redeploy this app to pull it.' : 'Pull it and restart.';
    }

    /**
     * Said when the primary action moves the image off an end-of-life OS.
     */
    private static function eolSentence(ResourceSecurity $security): string
    {
        if ($security->eol === null || $security->eol['name'] === '' || (int) data_get($security->facts, 'action_stats.0.eol', 0) < 1) {
            return '';
        }

        $name = ucfirst($security->eol['name']);

        return trim("It moves off {$name} {$security->eol['version']}").', which no longer gets security fixes.';
    }

    private static function problems(int $count): string
    {
        return $count > 1 ? "{$count} security problems" : 'a security problem';
    }

    /**
     * @return array<string, int|string|null>
     */
    private static function checkContext(ResourceSecurity $security): array
    {
        $context = $security->checkContext;
        if (($context['time'] ?? null) === null && $security->checkedAt !== null) {
            $context['time'] = $security->checkedAt->toIso8601String();
        }

        return $context;
    }

    private static function ago(mixed $time): string
    {
        if (! is_string($time) || $time === '') {
            return 'a while ago';
        }

        try {
            return CarbonImmutable::parse($time)->diffForHumans();
        } catch (Throwable) {
            return 'a while ago';
        }
    }
}
