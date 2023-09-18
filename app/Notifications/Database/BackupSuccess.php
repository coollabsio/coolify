<?php

namespace App\Notifications\Database;

use App\Models\ScheduledDatabaseBackup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BackupSuccess extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 1;
    public string $name;
    public string $frequency;

    public function __construct(ScheduledDatabaseBackup $backup, public $database)
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
        $mail = new MailMessage();
        $mail->subject("✅ Backup successfully done for {$this->database->name}");
        $mail->view('emails.backup-success', [
            'name' => $this->name,
            'frequency' => $this->frequency,
        ]);
        return $mail;
    }

    public function toDiscord(): string
    {
        return "✅ Database backup for {$this->name} with frequency of {$this->frequency} was successful.";
    }
    public function toTelegram(): array
    {
        $message = "✅ Database backup for {$this->name} with frequency of {$this->frequency} was successful.";
        return [
            "message" => $message,
        ];
    }
}
