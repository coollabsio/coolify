<?php

namespace App\Notifications\Database;

use App\Models\ScheduledDatabaseBackup;
use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\PushoverChannel;
use App\Notifications\Channels\TelegramChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BackupFailed extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 1;
    public string $name;
    public string $database_name;
    public string $frequency;

    public function __construct(ScheduledDatabaseBackup $backup, public $database, public $output)
    {
        $this->name = $database->name;
        $this->database_name = $database->database_name();
        $this->frequency = $backup->frequency;
    }

    public function via(object $notifiable): array
    {
        return [DiscordChannel::class, TelegramChannel::class, MailChannel::class, PushoverChannel::class];
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage();
        $mail->subject("Coolify: [ACTION REQUIRED] Backup FAILED for {$this->database->name}");
        $mail->view('emails.backup-failed', [
            'name' => $this->name,
            'database_name' => $this->database_name,
            'frequency' => $this->frequency,
            'output' => $this->output,
        ]);
        return $mail;
    }

    public function toDiscord(): string
    {
        return "Coolify: Database backup for {$this->name} (db:{$this->database_name}) with frequency of {$this->frequency} was FAILED.\n\nReason: {$this->output}";
    }
    public function toTelegram(): array
    {
        $message = "Coolify:  Database backup for {$this->name} (db:{$this->database_name}) with frequency of {$this->frequency} was FAILED.\n\nReason: {$this->output}";
        return [
            "message" => $message,
        ];
    }

    public function toPushover(): array
    {
        return [
            "message" => "Coolify:  Database backup for {$this->name} with frequency of {$this->frequency} was FAILED.\n\nReason: {$this->output}",
        ];
    }
}
