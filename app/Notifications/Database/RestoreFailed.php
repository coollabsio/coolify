<?php

namespace App\Notifications\Database;

use App\Models\ScheduledDatabaseBackup;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

class RestoreFailed extends CustomEmailNotification
{
    public string $name;

    public function __construct(ScheduledDatabaseBackup $backup, public $database, public ?string $output = null)
    {
        $this->onQueue('high');
        $this->name = $database->name;
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('backup_failed');
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject("Coolify: Database restore failed for {$this->database->name}");
        $mail->view('emails.restore-failed', [
            'name' => $this->name,
            'output' => $this->output,
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        $message = new DiscordMessage(
            title: ':x: Database restore failed',
            description: "Database restore for {$this->name} failed.",
            color: DiscordMessage::errorColor(),
        );

        if ($this->output) {
            $message->addField('Error Output', substr($this->output, 0, 1000), false);
        }

        return $message;
    }

    public function toTelegram(): array
    {
        $message = "Coolify: Database restore for {$this->name} failed.";
        if ($this->output) {
            $message .= "\n\nError: ".substr($this->output, 0, 500);
        }

        return [
            'message' => $message,
        ];
    }

    public function toPushover(): PushoverMessage
    {
        $errorMessage = $this->output ? '<br/><br/><b>Error:</b> '.substr($this->output, 0, 500) : '';

        return new PushoverMessage(
            title: 'Database restore failed',
            level: 'error',
            message: "Database restore for {$this->name} failed.{$errorMessage}",
        );
    }

    public function toSlack(): SlackMessage
    {
        $title = 'Database restore failed';
        $description = "Database restore for {$this->name} failed.";

        if ($this->output) {
            $description .= "\n\n*Error:* ".substr($this->output, 0, 1000);
        }

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
            'error_output' => $this->output,
            'url' => $url,
        ];
    }
}