<?php

namespace App\Notifications\Database;

use App\Models\ScheduledDatabaseBackup;
use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\EmailChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BackupSuccess extends Notification implements ShouldQueue
{
    use Queueable;

    public string $name;
    public string $frequency;

    public function __construct(ScheduledDatabaseBackup $backup, public $database)
    {
        $this->name = $database->name;
        $this->frequency = $backup->frequency;
    }

    public function via(object $notifiable): array
    {
        $channels = [];
        $isEmailEnabled = isEmailEnabled($notifiable);
        $isDiscordEnabled = data_get($notifiable, 'discord_enabled');
        $isSubscribedToEmailEvent = data_get($notifiable, 'smtp_notifications_database_backups');
        $isSubscribedToDiscordEvent = data_get($notifiable, 'discord_notifications_database_backups');

        if ($isEmailEnabled && $isSubscribedToEmailEvent) {
            $channels[] = EmailChannel::class;
        }
        if ($isDiscordEnabled && $isSubscribedToDiscordEvent) {
            $channels[] = DiscordChannel::class;
        }
        return $channels;
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
}
