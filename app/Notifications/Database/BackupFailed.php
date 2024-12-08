<?php

namespace App\Notifications\Database;

use App\Models\ScheduledDatabaseBackup;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use Illuminate\Notifications\Messages\MailMessage;
use App\Notifications\Dto\SlackMessage;

class BackupFailed extends CustomEmailNotification
{
    public string $name;

    public string $frequency;

    public function __construct(ScheduledDatabaseBackup $backup, public $database, public $output, public $database_name)
    {
        $this->onQueue('high');
        $this->name = $database->name;
        $this->frequency = $backup->frequency;
    }

    public function via(object $notifiable): array
    {
        return setNotificationChannels($notifiable, 'database_backups');
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject("Coolify: [ACTION REQUIRED] Backup FAILED for {$this->database->name}");
        $mail->view('emails.backup-failed', [
            'name' => $this->name,
            'database_name' => $this->database_name,
            'frequency' => $this->frequency,
            'output' => $this->output,
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
        $message->addField('Output', $this->output);

        return $message;
    }

    public function toTelegram(): array
    {
        $message = "Coolify: Database backup for {$this->name} (db:{$this->database_name}) with frequency of {$this->frequency} was FAILED.\n\nReason:\n{$this->output}";

        return [
            'message' => $message,
        ];
    }

    public function toSlack(): SlackMessage
    {
        $title = "Database backup failed";
        $description = "Database backup for {$this->name} (db:{$this->database_name}) has FAILED.";

        $description .= "\n\n**Frequency:** {$this->frequency}";
        $description .= "\n\n**Error Output:**\n{$this->output}";

        return new SlackMessage(
            title: $title,
            description: $description,
            color: SlackMessage::errorColor()
        );
    }
}
