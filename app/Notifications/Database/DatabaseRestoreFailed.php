<?php

namespace App\Notifications\Database;

use App\Models\StandalonePostgresql;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

class DatabaseRestoreFailed extends CustomEmailNotification
{
    public string $name;

    public string $error;

    public ?string $label;

    public function __construct(
        public StandalonePostgresql $database,
        string $error,
        ?string $label = null
    ) {
        $this->onQueue('high');

        $this->name = $database->name;
        $this->error = $error;
        $this->label = $label;
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('backup_failed');
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject("Coolify: Database restore failed for {$this->name}");
        $mail->view('emails.database-restore-failed', [
            'name' => $this->name,
            'error' => $this->error,
            'label' => $this->label,
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        $description = "Database restore for {$this->name} has failed.";
        if ($this->label) {
            $description .= " Attempted to restore from backup: {$this->label}";
        }

        $message = new DiscordMessage(
            title: ':x: Database restore failed',
            description: $description,
            color: DiscordMessage::errorColor(),
        );

        $message->addField('Error', $this->error, false);

        return $message;
    }

    public function toTelegram(): array
    {
        $message = "Coolify: Database restore for {$this->name} has failed.";
        if ($this->label) {
            $message .= " Backup: {$this->label}";
        }
        $message .= "\n\nError: {$this->error}";

        return [
            'message' => $message,
        ];
    }

    public function toPushover(): PushoverMessage
    {
        $message = "Database restore for {$this->name} has failed.";
        if ($this->label) {
            $message .= "<br/><b>Backup:</b> {$this->label}";
        }
        $message .= "<br/><br/><b>Error:</b> {$this->error}";

        return new PushoverMessage(
            title: 'Database restore failed',
            level: 'error',
            message: $message,
        );
    }

    public function toSlack(): SlackMessage
    {
        $title = 'Database restore failed';
        $description = "Database restore for {$this->name} has failed.";

        if ($this->label) {
            $description .= "\n\n*Backup:* {$this->label}";
        }
        $description .= "\n\n*Error:* {$this->error}";

        return new SlackMessage(
            title: $title,
            description: $description,
            color: SlackMessage::errorColor()
        );
    }

    public function toWebhook(): array
    {
        $url = base_url().'/project/'.data_get($this->database, 'environment.project.uuid').'/environment/'.data_get($this->database, 'environment.uuid').'/database/'.$this->database->uuid;

        return [
            'success' => false,
            'message' => 'Database restore failed',
            'event' => 'restore_failed',
            'database_name' => $this->name,
            'database_uuid' => $this->database->uuid,
            'engine' => 'pgbackrest',
            'backup_label' => $this->label,
            'error' => $this->error,
            'url' => $url,
        ];
    }
}
