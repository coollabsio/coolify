<?php

namespace App\Notifications\Server;

use App\Models\Server;
use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\EmailChannel;
use App\Notifications\Channels\NtfyChannel;
use App\Notifications\Channels\TelegramChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class HighDiskUsage extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public function __construct(public Server $server, public int $disk_usage, public int $docker_cleanup_threshold) {}

    public function via(object $notifiable): array
    {
        $channels = [];
        $isEmailEnabled = isEmailEnabled($notifiable);
        $isDiscordEnabled = data_get($notifiable, 'discord_enabled');
        $isTelegramEnabled = data_get($notifiable, 'telegram_enabled');
        $isNtfyEnabled = data_get($notifiable, 'ntfy_enabled');

        if ($isDiscordEnabled) {
            $channels[] = DiscordChannel::class;
        }
        if ($isEmailEnabled) {
            $channels[] = EmailChannel::class;
        }
        if ($isTelegramEnabled) {
            $channels[] = TelegramChannel::class;
        }
        if ($isNtfyEnabled) {
            $channels[] = NtfyChannel::class;
        }

        return $channels;
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject("Coolify: Server ({$this->server->name}) high disk usage detected!");
        $mail->view('emails.high-disk-usage', [
            'name' => $this->server->name,
            'disk_usage' => $this->disk_usage,
            'threshold' => $this->docker_cleanup_threshold,
        ]);

        return $mail;
    }

    public function toNtfy(): array
    {
        return [
            'title' => "Coolify: Server '{$this->server->name}' high disk usage detected!",
            'message' => "Disk usage: {$this->disk_usage}%. Threshold: {$this->cleanup_after_percentage}%.\nPlease cleanup your disk to prevent data-loss.\nHere are some tips: https://coolify.io/docs/knowledge-base/server/automated-cleanup.",
            'buttons' => 'view, Go to your dashboard, '.base_url().';',
        ];
    }

    public function toDiscord(): string
    {
        $message = "Coolify: Server '{$this->server->name}' high disk usage detected!\nDisk usage: {$this->disk_usage}%. Threshold: {$this->docker_cleanup_threshold}%.\nPlease cleanup your disk to prevent data-loss.\nHere are some tips: https://coolify.io/docs/knowledge-base/server/automated-cleanup.";

        return $message;
    }

    public function toTelegram(): array
    {
        return [
            'message' => "Coolify: Server '{$this->server->name}' high disk usage detected!\nDisk usage: {$this->disk_usage}%. Threshold: {$this->docker_cleanup_threshold}%.\nPlease cleanup your disk to prevent data-loss.\nHere are some tips: https://coolify.io/docs/knowledge-base/server/automated-cleanup.",
        ];
    }
}
