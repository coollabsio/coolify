<?php

namespace App\Notifications\Server;

use App\Models\Server;
use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\EmailChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class NotReachable extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Server $server)
    {

    }

    public function via(object $notifiable): array
    {
        $channels = [];
        $isEmailEnabled = data_get($notifiable, 'smtp_enabled');
        $isDiscordEnabled = data_get($notifiable, 'discord_enabled');
        $isSubscribedToEmailEvent = data_get($notifiable, 'smtp_notifications_status_changes');
        $isSubscribedToDiscordEvent = data_get($notifiable, 'discord_notifications_status_changes');

        // if ($isEmailEnabled && $isSubscribedToEmailEvent) {
        //     $channels[] = EmailChannel::class;
        // }
        if ($isDiscordEnabled && $isSubscribedToDiscordEvent) {
            $channels[] = DiscordChannel::class;
        }
        return $channels;
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage();
        // $fqdn = $this->fqdn;
        $mail->subject("⛔ Server '{$this->server->name}' is unreachable");
        // $mail->view('emails.application-status-changes', [
        //     'name' => $this->application_name,
        //     'fqdn' => $fqdn,
        //     'application_url' => $this->application_url,
        // ]);
        return $mail;
    }

    public function toDiscord(): string
    {
        $message = '⛔ Server \'' . $this->server->name . '\' is unreachable (could be a temporary issue). If you receive this more than twice in a row, please check your server.';
        return $message;
    }
}
