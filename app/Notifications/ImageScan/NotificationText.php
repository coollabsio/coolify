<?php

namespace App\Notifications\ImageScan;

/**
 * Escapes values for each notification channel. Names, image refs, and tags come from users and registries.
 */
final class NotificationText
{
    public static function plain(?string $value): string
    {
        return trim(preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', (string) $value) ?? '');
    }

    /** Discord markdown. */
    public static function discord(?string $value): string
    {
        return preg_replace('/([\\\\*_~`|>\[\]()#])/u', '\\\\$1', self::plain($value)) ?? '';
    }

    /** Slack mrkdwn: only &, <, and > have a meaning that escaping can stop. */
    public static function slack(?string $value): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], self::plain($value));
    }

    /** Pushover with `html=1`. */
    public static function html(?string $value): string
    {
        return htmlspecialchars(self::plain($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** Markdown of the email body; Blade escapes the HTML. */
    public static function mailMarkdown(?string $value): string
    {
        return preg_replace('/([\\\\`*_{}\[\]()#+\-.!|~=])/u', '\\\\$1', self::plain($value)) ?? '';
    }
}
