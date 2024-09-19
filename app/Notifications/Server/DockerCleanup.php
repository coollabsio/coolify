<?php

namespace App\Notifications\Server;

use App\Models\Server;
use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\ExternalChannel;
use App\Notifications\Channels\TelegramChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class DockerCleanup extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public function __construct(public Server $server, public string $message) {}

    public function via(object $notifiable): array
    {
        $channels = [];
        // $isEmailEnabled = isEmailEnabled($notifiable);
        $isDiscordEnabled = data_get($notifiable, 'discord_enabled');
        $isTelegramEnabled = data_get($notifiable, 'telegram_enabled');
        $isExternalEnabled = data_get($notifiable, 'external_enabled');

        if ($isDiscordEnabled) {
            $channels[] = DiscordChannel::class;
        }
        if ($isExternalEnabled) {
            $channels[] = ExternalChannel::class;
        }
        // if ($isEmailEnabled) {
        //     $channels[] = EmailChannel::class;
        // }
        if ($isTelegramEnabled) {
            $channels[] = TelegramChannel::class;
        }


        return $channels;
    }

    // public function toMail(): MailMessage
    // {
    //     $mail = new MailMessage();
    //     $mail->subject("Coolify: Server ({$this->server->name}) high disk usage detected!");
    //     $mail->view('emails.high-disk-usage', [
    //         'name' => $this->server->name,
    //         'disk_usage' => $this->disk_usage,
    //         'threshold' => $this->docker_cleanup_threshold,
    //     ]);
    //     return $mail;
    // }

    public function toExternal(): mixed {
        return [
            'event' => 'cleanup_complete',
            'server' => $this->server->name,
            'message' => $this->message
        ];
    }

    public function toDiscord(): string
    {
        $message = "Coolify: Server '{$this->server->name}' cleanup job done!\n\n{$this->message}";

        return $message;
    }

    public function toTelegram(): array
    {
        return [
            'message' => "Coolify: Server '{$this->server->name}' cleanup job done!\n\n{$this->message}",
        ];
    }
}
