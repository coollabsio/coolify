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

class BackupFailed extends CustomEmailNotification
{
    public string $resourceName;

    public string $target;

    public string $frequency;

    public ?string $url = null;

    public function __construct(ScheduledVolumeBackup $backup, public string $output)
    {
        $this->onQueue('high');
        $resource = $backup->targetResource();
        $this->resourceName = $resource?->name ?? 'Unknown resource';
        $this->target = $backup->targetType().' '.$backup->targetName();
        $this->frequency = $backup->frequency;

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
        return "Storage backup of {$this->target} ({$this->resourceName}) has FAILED.";
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject("Coolify: [ACTION REQUIRED] Storage backup FAILED for {$this->resourceName}");
        $mail->view('emails.volume-backup-result', [
            'summary' => $this->summary(),
            'frequency' => $this->frequency,
            'output' => $this->output,
            'url' => $this->url,
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        $message = new DiscordMessage(
            title: ':cross_mark: Storage backup failed',
            description: $this->summary(),
            color: DiscordMessage::errorColor(),
            isCritical: true,
        );

        $message->addField('Frequency', $this->frequency, true);
        $message->addField('Output', $this->output);
        if ($this->url) {
            $message->addField('Resource', '[Link]('.$this->url.')');
        }

        return $message;
    }

    public function toTelegram(): array
    {
        $data = ['message' => "Coolify: {$this->summary()}\n\nFrequency: {$this->frequency}\n\nReason:\n{$this->output}"];

        if ($this->url) {
            $data['buttons'] = [['text' => 'Open resource in Coolify', 'url' => $this->url]];
        }

        return $data;
    }

    public function toPushover(): PushoverMessage
    {
        return new PushoverMessage(
            title: 'Storage backup failed',
            level: 'error',
            message: "{$this->summary()}<br/><br/><b>Frequency:</b> {$this->frequency}.<br/><b>Reason:</b> {$this->output}",
            buttons: $this->url ? [['text' => 'Open resource in Coolify', 'url' => $this->url]] : [],
        );
    }

    public function toSlack(): SlackMessage
    {
        return new SlackMessage(
            title: 'Storage backup failed',
            description: $this->summary()."\n\n*Frequency:* {$this->frequency}\n\n*Error Output:* {$this->output}".($this->url ? "\n\n<{$this->url}|Open resource in Coolify>" : ''),
            color: SlackMessage::errorColor(),
        );
    }

    public function toWebhook(): array
    {
        return [
            'success' => false,
            'message' => 'Storage backup failed',
            'event' => 'volume_backup_failed',
            'resource_name' => $this->resourceName,
            'storage' => $this->target,
            'frequency' => $this->frequency,
            'error_output' => $this->output,
            'url' => $this->url,
        ];
    }
}
