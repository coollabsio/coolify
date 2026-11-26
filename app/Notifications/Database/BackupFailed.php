<?php

namespace App\Notifications\Database;

use App\Contracts\ThrottledNotification;
use App\Enums\BackupTrigger;
use App\Models\NotificationThrottle;
use App\Models\ScheduledDatabaseBackup;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Sent on the first failure, then at most once a day until a run of the backup succeeds again.
 */
class BackupFailed extends CustomEmailNotification implements ThrottledNotification
{
    public string $name;

    public string $frequency;

    public BackupTrigger $trigger = BackupTrigger::Scheduled;

    public function __construct(public ScheduledDatabaseBackup $backup, public $database, public $output, public $database_name, BackupTrigger $trigger = BackupTrigger::Scheduled)
    {
        $this->onQueue('high');
        $this->trigger = $trigger;
        $this->name = $database->name;
        $this->frequency = $backup->frequency;
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
        $mail->subject("Coolify: [ACTION REQUIRED] Database Backup FAILED for {$this->database->name}");
        $mail->view('emails.backup-failed', [
            'name' => $this->name,
            'database_name' => $this->database_name,
            'frequency' => $this->frequency,
            'trigger' => $this->trigger->label(),
            'output' => $this->output,
            'reminder' => NotificationThrottle::FAILURE_REMINDER_TEXT,
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        $message = new DiscordMessage(
            title: ':cross_mark: Database backup failed',
            description: "Database backup for {$this->name} (db:{$this->database_name}) has FAILED.",
            color: DiscordMessage::errorColor(),
            isCritical: true,
        );

        $message->addField('Frequency', $this->frequency, true);
        $message->addField('Trigger', $this->trigger->label(), true);
        $message->addField('Output', $this->output);

        return $message;
    }

    public function toTelegram(): array
    {
        $message = "Coolify: Database backup for {$this->name} (db:{$this->database_name}) with frequency of {$this->frequency} was FAILED.\nTrigger: {$this->trigger->label()}\n\nReason:\n{$this->output}";

        return [
            'message' => $message,
        ];
    }

    public function toPushover(): PushoverMessage
    {
        return new PushoverMessage(
            title: 'Database backup failed',
            level: 'error',
            message: "Database backup for {$this->name} (db:{$this->database_name}) was FAILED<br/><br/><b>Frequency:</b> {$this->frequency}.<br/><b>Trigger:</b> {$this->trigger->label()}<br/><b>Reason:</b> {$this->output}",
        );
    }

    public function toSlack(): SlackMessage
    {
        $title = 'Database backup failed';
        $description = "Database backup for {$this->name} (db:{$this->database_name}) has FAILED.";

        $description .= "\n\n*Frequency:* {$this->frequency}";
        $description .= "\n\n*Trigger:* {$this->trigger->label()}";
        $description .= "\n\n*Error Output:* {$this->output}";

        return new SlackMessage(
            title: $title,
            description: $description,
            color: SlackMessage::errorColor()
        );
    }

    public function toWebhook(): array
    {
        $url = $this->database->link() ?? base_url();

        return [
            'success' => false,
            'message' => 'Database backup failed',
            'event' => 'backup_failed',
            'database_name' => $this->name,
            'database_uuid' => $this->database->uuid,
            'database_type' => $this->database_name,
            'frequency' => $this->frequency,
            'trigger' => $this->trigger->value,
            'error_output' => $this->output,
            'url' => $url,
        ];
    }
}
