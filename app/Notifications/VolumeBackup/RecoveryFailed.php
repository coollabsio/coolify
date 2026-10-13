<?php

namespace App\Notifications\VolumeBackup;

use App\Jobs\VolumeBackupRecoveryJob;
use App\Models\ScheduledVolumeBackupExecution;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

class RecoveryFailed extends CustomEmailNotification
{
    public string $resourceName;

    public string $target;

    public string $reason;

    public bool $containersStopped;

    public ?string $url = null;

    public function __construct(public ScheduledVolumeBackupExecution $execution)
    {
        $this->onQueue('high');
        $backup = $execution->scheduledVolumeBackup;
        $resource = $backup?->targetResource();
        $this->resourceName = $resource?->name ?? 'Unknown resource';
        $this->target = $backup ? $backup->targetType().' '.$backup->targetName() : 'Unknown storage';
        $this->reason = match ($execution->recovery_error) {
            VolumeBackupRecoveryJob::CATEGORY_S3_AUTH => 'S3 credentials were rejected',
            VolumeBackupRecoveryJob::CATEGORY_S3_BUCKET => 'S3 bucket is missing',
            VolumeBackupRecoveryJob::CATEGORY_REMOTE_COMMAND => 'A command on the server failed',
            default => 'Unknown error',
        };
        $this->containersStopped = (bool) $execution->stop_recovery_pending;

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
        $summary = "Recovery after the storage backup of {$this->target} ({$this->resourceName}) failed: {$this->reason}.";

        return $this->containersStopped
            ? $summary.' The containers stopped for the backup are still stopped, and new backups of this storage are skipped until you retry the recovery.'
            : $summary.' The partial S3 upload was not removed. Retry the recovery after fixing the S3 storage.';
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject("Coolify: [ACTION REQUIRED] Storage backup recovery FAILED for {$this->resourceName}");
        $mail->view('emails.volume-backup-recovery-failed', [
            'summary' => $this->summary(),
            'url' => $this->url,
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        $message = new DiscordMessage(
            title: ':cross_mark: Storage backup recovery failed',
            description: $this->summary(),
            color: DiscordMessage::errorColor(),
            isCritical: $this->containersStopped,
        );

        if ($this->url) {
            $message->addField('Resource', '[Link]('.$this->url.')');
        }

        return $message;
    }

    public function toTelegram(): array
    {
        $data = ['message' => "Coolify: [ACTION REQUIRED] {$this->summary()}"];

        if ($this->url) {
            $data['buttons'] = [['text' => 'Open resource in Coolify', 'url' => $this->url]];
        }

        return $data;
    }

    public function toPushover(): PushoverMessage
    {
        return new PushoverMessage(
            title: 'Storage backup recovery failed',
            level: 'error',
            message: "[ACTION REQUIRED] {$this->summary()}",
            buttons: $this->url ? [['text' => 'Open resource in Coolify', 'url' => $this->url]] : [],
        );
    }

    public function toSlack(): SlackMessage
    {
        return new SlackMessage(
            title: 'Coolify: [ACTION REQUIRED] Storage backup recovery failed',
            description: $this->summary().($this->url ? "\n\n<{$this->url}|Open resource in Coolify>" : ''),
            color: SlackMessage::errorColor(),
        );
    }

    public function toWebhook(): array
    {
        return [
            'success' => false,
            'message' => 'Storage backup recovery failed',
            'event' => 'volume_backup_recovery_failed',
            'resource_name' => $this->resourceName,
            'storage' => $this->target,
            'execution_uuid' => $this->execution->uuid,
            'reason' => $this->execution->recovery_error,
            'containers_stopped' => $this->containersStopped,
            'url' => $this->url,
        ];
    }
}
