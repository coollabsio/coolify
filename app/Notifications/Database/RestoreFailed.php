<?php

namespace App\Notifications\Database;

use App\Models\StandalonePostgresql;
use App\Notifications\CustomEmailNotification;
use Illuminate\Notifications\Messages\MailMessage;

class RestoreFailed extends CustomEmailNotification
{
    public string $sanitizedError;

    public function __construct(
        public StandalonePostgresql $database,
        public string $errorMessage
    ) {
        $this->onQueue('high');
        $this->sanitizedError = $this->sanitizeErrorMessage($errorMessage);
    }

    private function sanitizeErrorMessage(string $message): string
    {
        // Truncate to safe length
        $message = mb_substr($message, 0, 500);

        // Remove newlines and excessive whitespace
        $message = preg_replace('/\s+/', ' ', $message);

        // Redact common sensitive patterns
        $message = preg_replace('/\/var\/lib\/[^\s]+/', '[PATH]', $message);
        $message = preg_replace('/\/data\/[^\s]+/', '[PATH]', $message);
        $message = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '[UUID]', $message);
        $message = preg_replace('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', '[IP]', $message);
        $message = preg_replace('/(password|token|key|secret)[\s:=]+[^\s]+/i', '$1=[REDACTED]', $message);

        return trim($message);
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
                'errorMessage' => $this->sanitizedError,
            ]);

        return $mail;
    }

    public function toDiscord(): string
    {
        $message = "❌ **Database Restore Failed**\n\n";
        $message .= "**Database:** {$this->database->name}\n";
        $message .= "**UUID:** `{$this->database->uuid}`\n";
        $message .= "**Error:** {$this->sanitizedError}\n";
        $message .= "\nPlease check server logs for full details.";

        return $message;
    }

    public function toTelegram(): array
    {
        $message = "❌ **Database Restore Failed**\n\n";
        $message .= "**Database:** {$this->database->name}\n";
        $message .= "**UUID:** `{$this->database->uuid}`\n";
        $message .= "**Error:** {$this->sanitizedError}\n";
        $message .= "\nPlease check server logs for full details.";

        return [
            'message' => $message,
        ];
    }
}
