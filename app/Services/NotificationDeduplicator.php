<?php

namespace App\Services;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Cache;

class NotificationDeduplicator
{
    public const DEFAULT_TTL = 900;

    /**
     * @param  array<int, string>  $recipients
     */
    public function shouldSend(object $notifiable, Notification $notification, string $channel, array $recipients, ?string $subject = null, ?string $body = null): bool
    {
        if (method_exists($notification, 'shouldDeduplicate') && ! $notification->shouldDeduplicate()) {
            return true;
        }

        $ttl = method_exists($notification, 'deduplicateFor')
            ? $notification->deduplicateFor()
            : self::DEFAULT_TTL;

        if ($ttl <= 0) {
            return true;
        }

        return Cache::add(
            $this->cacheKey($notifiable, $notification, $channel, $recipients, $subject, $body),
            true,
            $ttl,
        );
    }

    /**
     * @param  array<int, string>  $recipients
     */
    private function cacheKey(object $notifiable, Notification $notification, string $channel, array $recipients, ?string $subject, ?string $body): string
    {
        $semanticKey = method_exists($notification, 'deduplicationKey')
            ? $notification->deduplicationKey($notifiable, $channel)
            : null;

        $payload = $semanticKey
            ? $this->semanticFingerprint($notifiable, $notification, $channel, $recipients, $semanticKey)
            : $this->defaultFingerprint($notifiable, $notification, $channel, $recipients, $subject, $body);

        return 'notification-dedupe:'.hash('sha256', $payload);
    }

    /**
     * @param  array<int, string>  $recipients
     */
    private function semanticFingerprint(object $notifiable, Notification $notification, string $channel, array $recipients, string $semanticKey): string
    {
        return json_encode([
            'notification' => $notification::class,
            'notifiable' => $notifiable::class,
            'notifiable_id' => data_get($notifiable, 'id'),
            'channel' => $channel,
            'recipients' => $this->normalizeRecipients($recipients),
            'semantic_key' => $semanticKey,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<int, string>  $recipients
     */
    private function defaultFingerprint(object $notifiable, Notification $notification, string $channel, array $recipients, ?string $subject, ?string $body): string
    {
        return json_encode([
            'notification' => $notification::class,
            'notifiable' => $notifiable::class,
            'notifiable_id' => data_get($notifiable, 'id'),
            'channel' => $channel,
            'recipients' => $this->normalizeRecipients($recipients),
            'subject' => $subject,
            'body_hash' => hash('sha256', (string) $body),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<int, string>  $recipients
     * @return array<int, string>
     */
    private function normalizeRecipients(array $recipients): array
    {
        return collect($recipients)
            ->map(fn (string $recipient) => mb_strtolower(trim($recipient)))
            ->sort()
            ->values()
            ->all();
    }
}
