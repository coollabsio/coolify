<?php

namespace App\Notifications\Database;

use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

class RestoreSuccess extends CustomEmailNotification
{
    public string $name;

    public string $backupDate;

    public function __construct(
        ScheduledDatabaseBackup $backup,
        public $database,
        public ScheduledDatabaseBackupExecution $backupExecution
    ) {
        $this->onQueue('high');

        $this->name = $database->name;
        $this->backupDate = $backupExecution->created_at->toDateTimeString();
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('backup_success');
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject("Coolify: Database restore successful for {$this->database->name}");
        $mail->view('emails.restore-success', [
            'name' => $this->name,
            'backup_date' => $this->backupDate,
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        $message = new DiscordMessage(
            title: ':white_check_mark: Database restore successful',
            description: "Database restore for {$this->name} was successful.",
            color: DiscordMessage::successColor(),
        );

        $message->addField('Backup Date', $this->backupDate, true);

        return $message;
    }

    public function toTelegram(): array
    {
        $message = "Coolify: Database restore for {$this->name} from backup created at {$this->backupDate} was successful.";

        return [
            'message' => $message,
        ];
    }

    public function toPushover(): PushoverMessage
    {
        return new PushoverMessage(
            title: 'Database restore successful',
            level: 'success',
            message: "Database restore for {$this->name} was successful.<br/><br/><b>Backup Date:</b> {$this->backupDate}.",
        );
    }

    public function toSlack(): SlackMessage
    {
        $title = 'Database restore successful';
        $description = "Database restore for {$this->name} was successful.";

        $description .= "\n\n*Backup Date:* {$this->backupDate}";

        return new SlackMessage(
            title: $title,
            description: $description,
            color: SlackMessage::successColor()
        );
    }

    public function toWebhook(): array
    {
        $url = base_url().'/project/'.data_get($this->database, 'environment.project.uuid').'/environment/'.data_get($this->database, 'environment.uuid').'/database/'.$this->database->uuid;

        return [
            'success' => true,
            'message' => 'Database restore successful',
            'event' => 'restore_success',
            'database_name' => $this->name,
            'database_uuid' => $this->database->uuid,
            'backup_date' => $this->backupDate,
            'url' => $url,
        ];
    }
}