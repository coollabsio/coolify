<?php

namespace App\Notifications\Database;

use App\Models\ScheduledDatabaseBackup;
use App\Notifications\Dto\DiscordMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Notifications\Dto\SlackMessage;
class BackupSuccess extends Notification implements ShouldQueue
{
    use Queueable;

    public $backoff = 10;

    public $tries = 3;

    public string $name;

    public string $frequency;

    public function __construct(ScheduledDatabaseBackup $backup, public $database, public $database_name)
    {
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
        $mail->subject("Coolify: Backup successfully done for {$this->database->name}");
        $mail->view('emails.backup-success', [
            'name' => $this->name,
            'database_name' => $this->database_name,
            'frequency' => $this->frequency,
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        $message = new DiscordMessage(
            title: ':white_check_mark: Database backup successful',
            description: "Database backup for {$this->name} (db:{$this->database_name}) was successful.",
            color: DiscordMessage::successColor(),
        );

        $message->addField('Frequency', $this->frequency, true);

        return $message;
    }

    public function toTelegram(): array
    {
        $message = "Coolify: Database backup for {$this->name} (db:{$this->database_name}) with frequency of {$this->frequency} was successful.";

        return [
            'message' => $message,
        ];
    }

    public function toSlack(): SlackMessage
    {
        $title = "Database backup successful";
        $description = "Database backup for {$this->name} (db:{$this->database_name}) was successful.";

        $description .= "\n\n**Frequency:** {$this->frequency}";

        return new SlackMessage(
            title: $title,
            description: $description,
            color: SlackMessage::successColor()
        );
    }
}
