<?php

namespace App\Notifications\Database;

use App\Models\StandalonePostgresql;
use App\Notifications\CustomEmailNotification;
use Illuminate\Notifications\Messages\MailMessage;

class RestoreFailed extends CustomEmailNotification
{
    public function __construct(
        public StandalonePostgresql $database,
        public string $errorMessage
    ) {
        $this->onQueue('high');
    }

    public function via(object $notifiable): array
    {
        return setNotificationChannels($notifiable, 'database_backups');
    }

    public function toMail(): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("Coolify: Database Restore Failed - {$this->database->name}")
            ->view('emails.database-restore-failed', [
                'database' => $this->database,
                'errorMessage' => $this->errorMessage,
            ]);

        return $mail;
    }

    public function toDiscord(): string
    {
        $message = "❌ **Database Restore Failed**\n\n";
        $message .= "**Database:** {$this->database->name}\n";
        $message .= "**UUID:** {$this->database->uuid}\n";
        $message .= "**Error:** {$this->errorMessage}\n";
        $message .= "\nPlease check the logs for more details.";

        return $message;
    }

    public function toTelegram(): array
    {
        $message = "❌ **Database Restore Failed**\n\n";
        $message .= "**Database:** {$this->database->name}\n";
        $message .= "**UUID:** `{$this->database->uuid}`\n";
        $message .= "**Error:** {$this->errorMessage}\n";
        $message .= "\nPlease check the logs for more details.";

        return [
            'message' => $message,
        ];
    }
}
