<?php

namespace App\Traits;

use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\EmailChannel;
use App\Notifications\Channels\SlackChannel;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\Channels\PushoverChannel;
use Illuminate\Database\Eloquent\Model;

trait HasNotificationSettings
{
    protected $alwaysSendEvents = [
        'server_force_enabled',
        'server_force_disabled',
        'general',
        'test',
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
     * Get all enabled notification channels for an event
     */
    public function getEnabledChannels(string $event): array
    {
        $channels = [];

        $channelMap = [
            'email' => EmailChannel::class,
            'discord' => DiscordChannel::class,
            'telegram' => TelegramChannel::class,
            'slack' => SlackChannel::class,
            'pushover' => PushoverChannel::class,
        ];

        if ($event === 'general') {
            unset($channelMap['email']);
        }

        foreach ($channelMap as $channel => $channelClass) {
            if ($this->isNotificationEnabled($channel) && $this->isNotificationTypeEnabled($channel, $event)) {
                $channels[] = $channelClass;
            }
        }

        return $channels;
    }
}
