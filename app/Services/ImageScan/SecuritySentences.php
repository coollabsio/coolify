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
    /**
     * The sentence under the status line. `$builderImage`: Coolify builds the image with a builder (Nixpacks,
     * Railpack, static), which changes the no_fix text.
     */
    public static function headline(ResourceSecurity $security, bool $builderImage = false): string
    {
        return match ($security->status) {
            SecurityStatusType::UpdateNow => self::updateNow($security),
            SecurityStatusType::UpdateAvailable => self::updateAvailable($security),
            SecurityStatusType::NoFix => $builderImage ? SecurityActions::BUILDER_TEXT : self::noFix($security),
            SecurityStatusType::Ok => 'No action needed.',
            SecurityStatusType::CheckNeeded => 'Coolify needs to check this image again: '.self::checkReason((string) $security->checkReason, self::checkContext($security)).'.',
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
            default => null,
        };
        if ($unfixed === null) {
            return [];
        }

        return [$unfixed > 1
            ? "{$unfixed} more problems that attackers are using have no fix yet."
            : 'One more problem that attackers are using has no fix yet.'];
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

        $sentence = $action->sentence;
        if ($action->type === SecurityActionType::UpdateImage && str_starts_with($sentence, 'Update ') && $sentence !== SecurityActions::COOLIFY_TEXT && str_ends_with($sentence, '.')) {
            $fixed = $security->fixedByPrimary;
            $what = $fixed > 0 && $fixed < $security->problemCount ? "{$fixed} of them" : ($security->problemCount === 1 ? 'it' : 'them');
            $sentence = substr($sentence, 0, -1)." to fix {$what}.";
        }

        return "{$headline} {$sentence}";
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

        $sentence = trim(($action?->sentence ?? '')." This fixes {$fixed} serious {$noun}{$breakdown}.");
        if (! $security->exploitedWithoutFix) {
            $sentence .= ' Attackers are not known to use '.($fixed === 1 ? 'it' : 'them').'.';
        }

        return $sentence;
    }

    private static function noFix(ResourceSecurity $security): string
    {
        $problems = self::problems($security->problemCount);

        return "This image has {$problems} that attackers are using, and no fixed version exists yet. Coolify checks every day and tells you when a fix is out. Until then, limit who can reach this app, or ignore the problem if the affected part is not used.";
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
