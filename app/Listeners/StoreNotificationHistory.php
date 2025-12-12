<?php

namespace App\Listeners;

use App\Models\Team;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification;

class StoreNotificationHistory
{
    /**
     * Handle the event.
     */
    public function handle(NotificationSent $event): void
    {
        try {
            // Get team from notifiable
            $team = $this->getTeam($event->notifiable);
            if (! $team) {
                return;
            }

            // Determine channel name from channel class
            $channel = $this->getChannelName($event->channel);

            // Extract notification data
            $notification = $event->notification;
            $eventType = $this->getEventType($notification);
            $title = $this->extractTitle($notification, $event->channel, $event->notifiable);
            $message = $this->extractMessage($notification, $event->channel, $event->notifiable);
            $metadata = $this->extractMetadata($notification);

            // Store in history
            store_notification_history(
                team: $team,
                notificationType: get_class($notification),
                eventType: $eventType,
                channel: $channel,
                title: $title,
                message: $message,
                metadata: $metadata
            );
        } catch (\Throwable $e) {
            // Silently fail - don't break notification sending if history storage fails
            if (isDev()) {
                ray('Failed to store notification history: '.$e->getMessage());
            }
        }
    }

    /**
     * Get team from notifiable
     */
    protected function getTeam($notifiable): ?Team
    {
        if ($notifiable instanceof Team) {
            return $notifiable;
        }

        // Try to get team from notifiable
        if (method_exists($notifiable, 'team')) {
            return $notifiable->team;
        }

        if (property_exists($notifiable, 'team')) {
            return $notifiable->team;
        }

        return null;
    }

    /**
     * Get channel name from channel class
     */
    protected function getChannelName(string $channelClass): string
    {
        return match ($channelClass) {
            \App\Notifications\Channels\DiscordChannel::class => 'discord',
            \App\Notifications\Channels\EmailChannel::class => 'email',
            \App\Notifications\Channels\TelegramChannel::class => 'telegram',
            \App\Notifications\Channels\SlackChannel::class => 'slack',
            \App\Notifications\Channels\PushoverChannel::class => 'pushover',
            \App\Notifications\Channels\WebhookChannel::class => 'webhook',
            default => strtolower(class_basename($channelClass)),
        };
    }

    /**
     * Extract event type from notification class name
     */
    protected function getEventType(Notification $notification): string
    {
        $className = get_class($notification);
        $parts = explode('\\', $className);
        $shortName = end($parts);

        // Map notification classes to event types
        return match ($shortName) {
            'DeploymentSuccess' => 'deployment_success',
            'DeploymentFailed' => 'deployment_failure',
            'StatusChanged' => 'status_change',
            'BackupSuccess' => 'backup_success',
            'BackupFailed' => 'backup_failure',
            'BackupSuccessWithS3Warning' => 'backup_success',
            'TaskSuccess' => 'scheduled_task_success',
            'TaskFailed' => 'scheduled_task_failure',
            'DockerCleanupSuccess' => 'docker_cleanup_success',
            'DockerCleanupFailed' => 'docker_cleanup_failure',
            'HighDiskUsage' => 'server_disk_usage',
            'Reachable' => 'server_reachable',
            'Unreachable' => 'server_unreachable',
            'ServerPatchCheck' => 'server_patch',
            'TraefikVersionOutdated' => 'traefik_outdated',
            'ContainerRestarted', 'ContainerStopped' => 'status_change',
            'ForceEnabled' => 'server_force_enabled',
            'ForceDisabled' => 'server_force_disabled',
            'GeneralNotification' => 'general',
            'SslExpirationNotification' => 'ssl_certificate_renewal',
            'HetznerDeletionFailed' => 'hetzner_deletion_failure',
            'Test' => 'test',
            default => strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $shortName)),
        };
    }

    /**
     * Extract title from notification based on channel
     */
    protected function extractTitle(Notification $notification, string $channel, $notifiable): ?string
    {
        try {
            // Try to get title from channel-specific methods
            return match ($channel) {
                \App\Notifications\Channels\DiscordChannel::class => $this->getDiscordTitle($notification),
                \App\Notifications\Channels\SlackChannel::class => $this->getSlackTitle($notification),
                \App\Notifications\Channels\PushoverChannel::class => $this->getPushoverTitle($notification),
                \App\Notifications\Channels\EmailChannel::class => $this->getEmailTitle($notification, $notifiable),
                default => null,
            };
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Extract message from notification based on channel
     */
    protected function extractMessage(Notification $notification, string $channel, $notifiable): ?string
    {
        try {
            // Try to get message from channel-specific methods
            return match ($channel) {
                \App\Notifications\Channels\DiscordChannel::class => $this->getDiscordMessage($notification),
                \App\Notifications\Channels\SlackChannel::class => $this->getSlackMessage($notification),
                \App\Notifications\Channels\PushoverChannel::class => $this->getPushoverMessage($notification),
                \App\Notifications\Channels\TelegramChannel::class => $this->getTelegramMessage($notification, $notifiable),
                default => null,
            };
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Get Discord title
     */
    protected function getDiscordTitle(Notification $notification): ?string
    {
        if (method_exists($notification, 'toDiscord')) {
            try {
                $message = $notification->toDiscord();
                return $message->title ?? null;
            } catch (\Throwable $e) {
                return null;
            }
        }
        return null;
    }

    /**
     * Get Discord message
     */
    protected function getDiscordMessage(Notification $notification): ?string
    {
        if (method_exists($notification, 'toDiscord')) {
            try {
                $message = $notification->toDiscord();
                return $message->description ?? null;
            } catch (\Throwable $e) {
                return null;
            }
        }
        return null;
    }

    /**
     * Get Slack title
     */
    protected function getSlackTitle(Notification $notification): ?string
    {
        if (method_exists($notification, 'toSlack')) {
            try {
                $message = $notification->toSlack();
                return $message->title ?? null;
            } catch (\Throwable $e) {
                return null;
            }
        }
        return null;
    }

    /**
     * Get Slack message
     */
    protected function getSlackMessage(Notification $notification): ?string
    {
        if (method_exists($notification, 'toSlack')) {
            try {
                $message = $notification->toSlack();
                return $message->description ?? null;
            } catch (\Throwable $e) {
                return null;
            }
        }
        return null;
    }

    /**
     * Get Pushover title
     */
    protected function getPushoverTitle(Notification $notification): ?string
    {
        if (method_exists($notification, 'toPushover')) {
            try {
                $message = $notification->toPushover();
                return $message->title ?? null;
            } catch (\Throwable $e) {
                return null;
            }
        }
        return null;
    }

    /**
     * Get Pushover message
     */
    protected function getPushoverMessage(Notification $notification): ?string
    {
        if (method_exists($notification, 'toPushover')) {
            try {
                $message = $notification->toPushover();
                return $message->message ?? null;
            } catch (\Throwable $e) {
                return null;
            }
        }
        return null;
    }

    /**
     * Get Telegram message
     */
    protected function getTelegramMessage(Notification $notification, $notifiable): ?string
    {
        if (method_exists($notification, 'toTelegram')) {
            try {
                $data = $notification->toTelegram($notifiable);
                return is_array($data) ? ($data['message'] ?? null) : null;
            } catch (\Throwable $e) {
                return null;
            }
        }
        return null;
    }

    /**
     * Get Email title (subject)
     */
    protected function getEmailTitle(Notification $notification, $notifiable): ?string
    {
        if (method_exists($notification, 'toMail')) {
            try {
                $mail = $notification->toMail($notifiable);
                return $mail->subject ?? null;
            } catch (\Throwable $e) {
                return null;
            }
        }
        return null;
    }

    /**
     * Extract metadata from notification
     */
    protected function extractMetadata(Notification $notification): array
    {
        $metadata = [];

        // Extract common properties from notification
        $properties = [
            'application_name',
            'deployment_uuid',
            'deployment_url',
            'project_uuid',
            'environment_uuid',
            'environment_name',
            'fqdn',
        ];

        foreach ($properties as $property) {
            if (property_exists($notification, $property)) {
                $value = $notification->$property ?? null;
                if ($value !== null) {
                    // Map environment_name to environment for consistency
                    $key = $property === 'environment_name' ? 'environment' : $property;
                    $metadata[$key] = $value;
                }
            }
        }

        // Try to extract project name from application if available
        if (property_exists($notification, 'application') && $notification->application) {
            try {
                $application = $notification->application;
                if (method_exists($application, 'environment') && $application->environment) {
                    $environment = $application->environment;
                    if (method_exists($environment, 'project') && $environment->project) {
                        $metadata['project'] = $environment->project->name ?? null;
                    }
                }
            } catch (\Throwable $e) {
                // Ignore errors when accessing relationships
            }
        }

        return array_filter($metadata);
    }
}
