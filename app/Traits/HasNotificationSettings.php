<?php

namespace App\Traits;

use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\EmailChannel;
use App\Notifications\Channels\SlackChannel;
use App\Notifications\Channels\TelegramChannel;
use Illuminate\Database\Eloquent\Model;

trait HasNotificationSettings
{
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
     * Get all enabled notification channels for an event
     */
    public function getEnabledChannels(string $event): array
    {
        $alwaysSendEvents = [
            'server_force_enabled',
            'server_force_disabled',
            'general',
        ];

        $channels = [];

        $channelMap = [
            'email' => EmailChannel::class,
            'discord' => DiscordChannel::class,
            'telegram' => TelegramChannel::class,
            'slack' => SlackChannel::class,
        ];

        foreach ($channelMap as $channel => $channelClass) {
            if (in_array($event, $alwaysSendEvents)) {
                if ($this->isNotificationEnabled($channel)) {
                    $channels[] = $channelClass;
                }
            } else {
                if ($this->isNotificationEnabled($channel) && $this->isNotificationTypeEnabled($channel, $event)) {
                    $channels[] = $channelClass;
                }
            }
        }

        return $channels;
    }
}
