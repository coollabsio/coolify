<?php

namespace App\Traits;

use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\EmailChannel;
use App\Notifications\Channels\PushoverChannel;
use App\Notifications\Channels\SlackChannel;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\Channels\WebhookChannel;
use Illuminate\Database\Eloquent\Model;

trait HasNotificationSettings
{
    protected $alwaysSendEvents = [
        'server_force_enabled',
        'server_force_disabled',
        'general',
        'test',
        'ssl_certificate_renewal',
        'hetzner_deletion_failure',
    ];

    /**
     * Get settings model for specific channel
     */
    public function getNotificationSettings(string $channel): ?Model
    {
        return match ($channel) {
            'email' => $this->emailNotificationSettings,
            'discord' => $this->discordNotificationSettings,
            'telegram' => $this->telegramNotificationSettings,
            'slack' => $this->slackNotificationSettings,
            'pushover' => $this->pushoverNotificationSettings,
            'webhook' => $this->webhookNotificationSettings,
            default => null,
        };
    }

    /**
     * Check if a notification channel is enabled
     */
    public function isNotificationEnabled(string $channel): bool
    {
        $settings = $this->getNotificationSettings($channel);

        return $settings?->isEnabled() ?? false;
    }

    /**
     * Check if a specific notification type is enabled for a channel
     */
    public function isNotificationTypeEnabled(string $channel, string $event): bool
    {
        $settings = $this->getNotificationSettings($channel);

        if (! $settings || ! $this->isNotificationEnabled($channel)) {
            return false;
        }

        if (in_array($event, $this->alwaysSendEvents)) {
            return true;
        }

        $settingKey = "{$event}_{$channel}_notifications";

        return (bool) $settings->$settingKey;
    }

    /**
     * Map event types to their bundle setting column name.
     */
    protected static array $bundleSettingMap = [
        'server_patch' => 'bundle_patch_notifications',
        'traefik_outdated' => 'bundle_traefik_notifications',
    ];

    /**
     * Get all enabled notification channels for an event.
     *
     * @param  string  $event  The event type (e.g. 'server_patch', 'traefik_outdated')
     * @param  bool  $bundledOnly  Only return channels that have bundling enabled for this event
     * @param  bool  $unbundledOnly  Only return channels that have bundling disabled for this event
     */
    public function getEnabledChannels(string $event, bool $bundledOnly = false, bool $unbundledOnly = false): array
    {
        $channels = [];

        $channelMap = [
            'email' => EmailChannel::class,
            'discord' => DiscordChannel::class,
            'telegram' => TelegramChannel::class,
            'slack' => SlackChannel::class,
            'pushover' => PushoverChannel::class,
            'webhook' => WebhookChannel::class,
        ];

        if ($event === 'general') {
            unset($channelMap['email']);
        }

        $bundleColumn = static::$bundleSettingMap[$event] ?? null;

        foreach ($channelMap as $channel => $channelClass) {
            if ($this->isNotificationEnabled($channel) && $this->isNotificationTypeEnabled($channel, $event)) {
                if ($bundleColumn && ($bundledOnly || $unbundledOnly)) {
                    $settings = $this->getNotificationSettings($channel);
                    $isBundled = (bool) ($settings->$bundleColumn ?? false);

                    if ($bundledOnly && ! $isBundled) {
                        continue;
                    }
                    if ($unbundledOnly && $isBundled) {
                        continue;
                    }
                }

                $channels[] = $channelClass;
            }
        }

        return $channels;
    }
}
