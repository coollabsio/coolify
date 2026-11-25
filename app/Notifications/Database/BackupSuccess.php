<?php

namespace App\Notifications\Database;

use App\Enums\BackupTrigger;
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

    public BackupTrigger $trigger = BackupTrigger::Scheduled;

    public function __construct(ScheduledDatabaseBackup $backup, public $database, public $database_name, BackupTrigger $trigger = BackupTrigger::Scheduled)
    {
        $this->onQueue('high');
        $this->trigger = $trigger;

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
            'trigger' => $this->trigger->label(),
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
        $message->addField('Trigger', $this->trigger->label(), true);

        return $message;
    }

    public function toTelegram(): array
    {
        $message = "Coolify: Database backup for {$this->name} (db:{$this->database_name}) with frequency of {$this->frequency} was successful.\nTrigger: {$this->trigger->label()}";

        return [
            'message' => $message,
        ];
    }

    public function toPushover(): PushoverMessage
    {
        return new PushoverMessage(
            title: 'Database backup successful',
            level: 'success',
            message: "Database backup for {$this->name} (db:{$this->database_name}) was successful.<br/><br/><b>Frequency:</b> {$this->frequency}.<br/><b>Trigger:</b> {$this->trigger->label()}",
        );
    }

    public function toSlack(): SlackMessage
    {
        $title = 'Database backup successful';
        $description = "Database backup for {$this->name} (db:{$this->database_name}) was successful.";

        $description .= "\n\n*Frequency:* {$this->frequency}";
        $description .= "\n\n*Trigger:* {$this->trigger->label()}";

        return new SlackMessage(
            title: $title,
            description: $description,
            color: SlackMessage::successColor()
        );
    }

    public function toWebhook(): array
    {
        $url = $this->database->link() ?? base_url();

        return [
            'success' => true,
            'message' => 'Database backup successful',
            'event' => 'backup_success',
            'database_name' => $this->name,
            'database_uuid' => $this->database->uuid,
            'database_type' => $this->database_name,
            'frequency' => $this->frequency,
            'trigger' => $this->trigger->value,
            'url' => $url,
        ];
    }
}
