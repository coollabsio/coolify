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

    /**
     * Cuts a raw value before it is escaped, so one long name cannot fill a message.
     */
    public static function limit(?string $value, int $max): string
    {
        $value = self::plain($value);

        return mb_strlen($value) > $max ? rtrim(mb_substr($value, 0, $max - 1)).'…' : $value;
    }

    /**
     * The lines that fit in $budget characters, joined with new lines, then "And N more." for the rest.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    public static function fitLines(array $lines, int $budget): array
    {
        $kept = [];
        $used = 0;
        $count = count($lines);
        foreach ($lines as $index => $line) {
            $remaining = $count - $index - 1;
            $reserve = $remaining > 0 ? mb_strlen("\nAnd {$remaining} more.") : 0;
            $length = mb_strlen($line) + ($kept === [] ? 0 : 1);
            if ($used + $length + $reserve > $budget) {
                break;
            }
            $kept[] = $line;
            $used += $length;
        }

        $more = $count - count($kept);
        if ($more > 0) {
            $kept[] = "And {$more} more.";
        }

        return $kept;
    }
}
