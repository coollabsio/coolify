<?php

namespace App\Notifications\VolumeBackup;

use App\Models\ScheduledVolumeBackup;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;

class BackupMissing extends CustomEmailNotification
{
    public string $resourceName;

    public string $target;

    public ?string $url = null;

    public function __construct(public ScheduledVolumeBackup $backup, public ?Carbon $lastExecutionAt)
    {
        $this->onQueue('high');
        $resource = $backup->targetResource();
        $this->resourceName = $resource?->name ?? 'Unknown resource';
        $this->target = $backup->targetType().' '.$backup->targetName();

        $linkable = $resource instanceof ServiceApplication || $resource instanceof ServiceDatabase ? $resource->service : $resource;
        if ($linkable && method_exists($linkable, 'link')) {
            $this->url = $linkable->link();
        }
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('backup_failure');
    }

    public function summary(): string
    {
        return "The enabled storage backup schedule for {$this->target} ({$this->resourceName}) has produced no executions in the last {$this->backup->missing_backup_notification_days} day(s).";
    }

    public function toMail(): MailMessage
    {
        return (new MailMessage)
            ->subject("Coolify: [ACTION REQUIRED] No recent storage backup for {$this->resourceName}")
            ->view('emails.volume-backup-missing', [
                'summary' => $this->summary(),
                'last_execution_at' => $this->lastExecutionAt?->toDateTimeString(),
                'url' => $this->url,
            ]);
    }

    public function toDiscord(): DiscordMessage
    {
        $message = new DiscordMessage(
            title: ':warning: Scheduled storage backup missing',
            description: $this->summary(),
            color: DiscordMessage::errorColor(),
            isCritical: true,
        );

        if ($this->url) {
            $message->addField('Resource', '[Link]('.$this->url.')');
        }

        return $message;
    }

    public function toTelegram(): array
    {
        $data = ['message' => "Coolify: {$this->summary()}"];

        if ($this->url) {
            $data['buttons'] = [['text' => 'Open resource in Coolify', 'url' => $this->url]];
        }

        return $data;
    }

    public function toPushover(): PushoverMessage
    {
        return new PushoverMessage(
            title: 'Scheduled storage backup missing',
            level: 'error',
            message: $this->summary(),
            buttons: $this->url ? [['text' => 'Open resource in Coolify', 'url' => $this->url]] : [],
        );
    }

    public function toSlack(): SlackMessage
    {
        return new SlackMessage(
            title: 'Scheduled storage backup missing',
            description: $this->summary().($this->url ? "\n\n<{$this->url}|Open resource in Coolify>" : ''),
            color: SlackMessage::errorColor(),
        );
    }

    public function toWebhook(): array
    {
        return [
            'success' => false,
            'message' => 'Scheduled storage backup missing',
            'event' => 'volume_backup_missing',
            'resource_name' => $this->resourceName,
            'storage' => $this->target,
            'backup_uuid' => $this->backup->uuid,
            'days' => $this->backup->missing_backup_notification_days,
            'last_execution_at' => $this->lastExecutionAt?->toDateTimeString(),
            'url' => $this->url,
        ];
    }
}
