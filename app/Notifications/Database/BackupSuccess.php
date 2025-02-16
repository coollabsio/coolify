<?php

namespace App\Notifications\Database;

use App\Models\ScheduledDatabaseBackup;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

class BackupSuccess extends CustomEmailNotification
{
    public string $name;

    public string $frequency;

    public function __construct(ScheduledDatabaseBackup $backup, public $database, public $database_name)
    {
        $this->onQueue('high');

        $this->name = $database->name;
        $this->frequency = $backup->frequency;
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('backup_success');
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

    public function toPushover(): PushoverMessage
    {
        return new PushoverMessage(
            title: 'Database backup successful',
            level: 'success',
            message: "Database backup for {$this->name} (db:{$this->database_name}) was successful.<br/><br/><b>Frequency:</b> {$this->frequency}.",
        );
    }

    public function toSlack(): SlackMessage
    {
        $title = 'Database backup successful';
        $description = "Database backup for {$this->name} (db:{$this->database_name}) was successful.";

        $description .= "\n\n*Frequency:* {$this->frequency}";

        return new SlackMessage(
            title: $title,
            description: $description,
            color: SlackMessage::successColor()
        );
    }
}
