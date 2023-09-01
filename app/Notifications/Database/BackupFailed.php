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

    public string $message = 'Backup FAILED';


    public function __construct(ScheduledDatabaseBackup $backup, public $database, public $output)
    {
        $this->message = "❌ Database backup for {$database->name} with frequency of $backup->frequency was FAILED.\n\nReason: $output";
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
        ray($channels);
        return $channels;
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage();
        $mail->subject("❌ Backup FAILED for {$this->database->name}");
        $mail->line($this->message);
        return $mail;
    }

    public function toDiscord(): string
    {
        return $this->message;
    }
}
