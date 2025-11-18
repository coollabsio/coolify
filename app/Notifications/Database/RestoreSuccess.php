<?php

namespace App\Notifications\Database;

use App\Models\StandalonePostgresql;
use App\Notifications\CustomEmailNotification;
use Illuminate\Notifications\Messages\MailMessage;

class RestoreSuccess extends CustomEmailNotification
{
    public function __construct(
        public StandalonePostgresql $database,
        public ?string $backupLabel = null,
        public ?string $targetTime = null
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
            ->subject("Coolify: Database Restore Successful - {$this->database->name}")
            ->view('emails.database-restore-success', [
                'database' => $this->database,
                'backupLabel' => $this->backupLabel,
                'targetTime' => $this->targetTime,
            ]);

        return $mail;
    }

    public function toDiscord(): string
    {
        $message = "✅ **Database Restore Successful**\n\n";
        $message .= "**Database:** {$this->database->name}\n";
        $message .= "**UUID:** {$this->database->uuid}\n";

        if ($this->backupLabel) {
            $message .= "**Backup Label:** {$this->backupLabel}\n";
        }

        if ($this->targetTime) {
            $message .= "**Restored to:** {$this->targetTime}\n";
        }

        $message .= "\nThe database has been successfully restored and is now running.";

        return $message;
    }

    public function toTelegram(): array
    {
        $message = "✅ **Database Restore Successful**\n\n";
        $message .= "**Database:** {$this->database->name}\n";
        $message .= "**UUID:** `{$this->database->uuid}`\n";

        if ($this->backupLabel) {
            $message .= "**Backup Label:** `{$this->backupLabel}`\n";
        }

        if ($this->targetTime) {
            $message .= "**Restored to:** {$this->targetTime}\n";
        }

        $message .= "\nThe database has been successfully restored and is now running.";

        return [
            'message' => $message,
        ];
    }
}
