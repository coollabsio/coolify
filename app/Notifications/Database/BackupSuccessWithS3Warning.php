<?php

namespace App\Notifications\Database;

use App\Contracts\ThrottledNotification;
use App\Enums\BackupTrigger;
use App\Models\NotificationThrottle;
use App\Models\ScheduledDatabaseBackup;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\NtfyMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Sent on the first failed S3 upload, then at most once a day until a run of the backup has no problem again.
 */
class BackupSuccessWithS3Warning extends CustomEmailNotification implements ThrottledNotification
{
    public string $name;

    public string $frequency;

    public BackupTrigger $trigger = BackupTrigger::Scheduled;

    public ?string $s3_storage_url = null;

    public function __construct(public ScheduledDatabaseBackup $backup, public $database, public $database_name, public $s3_error, BackupTrigger $trigger = BackupTrigger::Scheduled)
    {
        $this->onQueue('high');
        $this->trigger = $trigger;

        $this->name = $database->name;
        $this->frequency = $backup->frequency;

        if ($backup->s3) {
            $this->s3_storage_url = base_url().'/storages/'.$backup->s3->uuid;
        }
    }

    public function throttleSubject(): ?Model
    {
        return $this->backup;
    }

    public function throttleIntervalMinutes(): int
    {
        return NotificationThrottle::FAILURE_REMINDER_INTERVAL_MINUTES;
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('backup_failure');
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject("Coolify: Backup succeeded locally but the storage upload failed for {$this->database->name}");
        $mail->view('emails.backup-success-with-s3-warning', [
            'name' => $this->name,
            'database_name' => $this->database_name,
            'frequency' => $this->frequency,
            'trigger' => $this->trigger->label(),
            's3_error' => $this->s3_error,
            's3_storage_url' => $this->s3_storage_url,
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        $message = new DiscordMessage(
            title: ':warning: Database backup succeeded locally, storage upload failed',
            description: "Database backup for {$this->name} (db:{$this->database_name}) was created successfully on local storage, but failed to upload to the backup storage.",
            color: DiscordMessage::warningColor(),
        );

        $message->addField('Frequency', $this->frequency, true);
        $message->addField('Trigger', $this->trigger->label(), true);
        $message->addField('Storage Error', $this->s3_error);

        if ($this->s3_storage_url) {
            $message->addField('Storage', '[Check Configuration]('.$this->s3_storage_url.')');
        }

        return $message;
    }

    public function toTelegram(): array
    {
        $message = "Coolify: Database backup for {$this->name} (db:{$this->database_name}) with frequency of {$this->frequency} succeeded locally but failed to upload to the backup storage.\nTrigger: {$this->trigger->label()}\n\nStorage Error:\n{$this->s3_error}";

        if ($this->s3_storage_url) {
            $message .= "\n\nCheck Storage Configuration: {$this->s3_storage_url}";
        }

        return [
            'message' => $message,
        ];
    }

    public function toPushover(): PushoverMessage
    {
        $message = "Database backup for {$this->name} (db:{$this->database_name}) was created successfully on local storage, but failed to upload to the backup storage.<br/><br/><b>Frequency:</b> {$this->frequency}.<br/><b>Trigger:</b> {$this->trigger->label()}<br/><b>Storage Error:</b> {$this->s3_error}";

        if ($this->s3_storage_url) {
            $message .= "<br/><br/><a href=\"{$this->s3_storage_url}\">Check Storage Configuration</a>";
        }

        return new PushoverMessage(
            title: 'Database backup succeeded locally, storage upload failed',
            level: 'warning',
            message: $message,
        );
    }

    public function toNtfy(): NtfyMessage
    {
        $message = "Database backup for {$this->name} (db:{$this->database_name}) was created successfully on local storage, but failed to upload to S3.\n\nFrequency: {$this->frequency}.\nS3 Error: {$this->s3_error}";

        if ($this->s3_storage_url) {
            $message .= "\n\nCheck S3 Configuration: {$this->s3_storage_url}";
        }

        return new NtfyMessage(
            title: 'Database backup succeeded locally, S3 upload failed',
            level: 'warning',
            message: $message,
        );
    }

    public function toSlack(): SlackMessage
    {
        $title = 'Database backup succeeded locally, storage upload failed';
        $description = "Database backup for {$this->name} (db:{$this->database_name}) was created successfully on local storage, but failed to upload to the backup storage.";

        $description .= "\n\n*Frequency:* {$this->frequency}";
        $description .= "\n\n*Trigger:* {$this->trigger->label()}";
        $description .= "\n\n*Storage Error:* {$this->s3_error}";

        if ($this->s3_storage_url) {
            $description .= "\n\n*Storage:* <{$this->s3_storage_url}|Check Configuration>";
        }

        return new SlackMessage(
            title: $title,
            description: $description,
            color: SlackMessage::warningColor()
        );
    }

    public function toWebhook(): array
    {
        $url = $this->database->link() ?? base_url();

        $data = [
            'success' => true,
            'message' => 'Database backup succeeded locally, S3 upload failed',
            'event' => 'backup_success_with_s3_warning',
            'database_name' => $this->name,
            'database_uuid' => $this->database->uuid,
            'database_type' => $this->database_name,
            'frequency' => $this->frequency,
            'trigger' => $this->trigger->value,
            's3_error' => $this->s3_error,
            'url' => $url,
        ];

        if ($this->s3_storage_url) {
            $data['s3_storage_url'] = $this->s3_storage_url;
        }

        return $data;
    }
}
