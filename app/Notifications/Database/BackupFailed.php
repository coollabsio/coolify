<?php

namespace App\Notifications\Database;

use App\Models\ScheduledDatabaseBackup;
use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\EmailChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BackupFailed extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 5;
    public string $name;
    public string $frequency;

    public function __construct(ScheduledDatabaseBackup $backup, public $database, public $output)
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
        $mail->subject("❌ [ACTION REQUIRED] Backup FAILED for {$this->database->name}");
        $mail->view('emails.backup-failed', [
            'name' => $this->name,
            'frequency' => $this->frequency,
            'output' => $this->output,
        ]);
        return $mail;
    }

    public function toDiscord(): string
    {
        return "❌ Database backup for {$this->name} with frequency of {$this->frequency} was FAILED.\n\nReason: {$this->output}";
    }
    public function toTelegram(): array
    {
        $message = "❌ Database backup for {$this->name} with frequency of {$this->frequency} was FAILED.\n\nReason: {$this->output}";
        return [
            "message" => $message,
        ];
    }
}
