<?php

namespace App\Notifications\VolumeBackup;

use App\Contracts\ThrottledNotification;
use App\Models\NotificationThrottle;
use App\Models\ScheduledVolumeBackup;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * With a warning, it goes to the failure channels: then it is sent at most once a day until a run has no warning again.
 */
class BackupSuccess extends CustomEmailNotification implements ThrottledNotification
{
    public string $resourceName;

    public string $target;

    public string $frequency;

    public ?string $url = null;

    public function __construct(public ScheduledVolumeBackup $backup, public ?string $warning = null)
    {
        $this->onQueue('high');
        $resource = $backup->targetResource();
        $this->resourceName = $resource?->name ?? 'Unknown resource';
        $this->target = $backup->targetType().' '.$backup->targetName();
        $this->frequency = $backup->frequency;

        $this->url = $backup->resourceUrl();
    }

    public function throttleSubject(): ?Model
    {
        return $this->warning ? $this->backup : null;
    }

    public function throttleIntervalMinutes(): int
    {
        return NotificationThrottle::FAILURE_REMINDER_INTERVAL_MINUTES;
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels($this->warning ? 'backup_failure' : 'backup_success');
    }

    public function summary(): string
    {
        $summary = "Storage backup of {$this->target} ({$this->resourceName}) was successful.";

        return $this->warning ? $summary." Warning: {$this->warning}" : $summary;
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject($this->warning
            ? "Coolify: Storage backup succeeded with a warning for {$this->resourceName}"
            : "Coolify: Storage backup successfully done for {$this->resourceName}");
        $mail->view('emails.volume-backup-result', [
            'summary' => $this->summary(),
            'frequency' => $this->frequency,
            'url' => $this->url,
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        $message = new DiscordMessage(
            title: $this->warning ? ':warning: Storage backup succeeded with a warning' : ':white_check_mark: Storage backup successful',
            description: $this->summary(),
            color: $this->warning ? DiscordMessage::warningColor() : DiscordMessage::successColor(),
        );

        $message->addField('Frequency', $this->frequency, true);
        if ($this->url) {
            $message->addField('Resource', '[Link]('.$this->url.')');
        }

        return $message;
    }

    public function toTelegram(): array
    {
        $data = ['message' => "Coolify: {$this->summary()}\n\nFrequency: {$this->frequency}"];

        if ($this->url) {
            $data['buttons'] = [['text' => 'Open resource in Coolify', 'url' => $this->url]];
        }

        return $data;
    }

    public function toPushover(): PushoverMessage
    {
        return new PushoverMessage(
            title: $this->warning ? 'Storage backup succeeded with a warning' : 'Storage backup successful',
            level: $this->warning ? 'warning' : 'success',
            message: "{$this->summary()}<br/><br/><b>Frequency:</b> {$this->frequency}.",
            buttons: $this->url ? [['text' => 'Open resource in Coolify', 'url' => $this->url]] : [],
        );
    }

    public function toSlack(): SlackMessage
    {
        return new SlackMessage(
            title: $this->warning ? 'Storage backup succeeded with a warning' : 'Storage backup successful',
            description: $this->summary()."\n\n*Frequency:* {$this->frequency}".($this->url ? "\n\n<{$this->url}|Open resource in Coolify>" : ''),
            color: $this->warning ? SlackMessage::warningColor() : SlackMessage::successColor(),
        );
    }

    public function toWebhook(): array
    {
        return [
            'success' => true,
            'message' => $this->warning ? 'Storage backup succeeded with a warning' : 'Storage backup successful',
            'event' => 'volume_backup_success',
            'resource_name' => $this->resourceName,
            'storage' => $this->target,
            'frequency' => $this->frequency,
            'warning' => $this->warning,
            'url' => $this->url,
        ];
    }
}
