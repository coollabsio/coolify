<?php

namespace App\Services\ImageScan;

use App\Enums\SecurityActionType;
use App\Enums\SecurityStatusType;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The copy of the security status card (spec 15.5, 15.7). Pure functions of the stored `ResourceSecurity`.
 */
final class SecuritySentences
{
    private const CHECK_REASONS = [
        SecurityStatus::REASON_IMAGES_UNCHECKED,
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

    private static function updateNow(ResourceSecurity $security): string
    {
        $problems = self::problems($security->problemCount);
        $use = $security->anyExploited ? 'are using' : 'are likely to use';
        $headline = "This image has {$problems} that attackers {$use}.";

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
            $sentence = "A newer build of this image fixes {$what}. ".self::pullInstruction($sentence);
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

    private static function noFix(ResourceSecurity $security): string
    {
        $problems = self::problems($security->problemCount);
        $use = $security->anyExploited ? 'are using' : 'are likely to use';

        if ($security->builderImage) {
            return "This image has {$problems} that attackers {$use}. ".SecurityActions::BUILDER_TEXT;
        }

        $many = $security->problemCount > 1;
        $ignore = $many ? 'ignore the problems if the affected parts are not used' : 'ignore the problem if the affected part is not used';

        return "This image has {$problems} that attackers {$use}, and no fixed version exists yet. Coolify checks every day and tells you when a fix is out. Until then, limit who can reach this app, or {$ignore}.";
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
