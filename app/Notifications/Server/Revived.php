<?php

namespace App\Notifications\Server;

use App\Actions\Docker\GetContainersStatus;
use App\Jobs\ContainerStatusJob;
use App\Models\Server;
use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\EmailChannel;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\Dto\DiscordMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\RateLimiter;

class Revived extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public function __construct(public Server $server)
    {
        if ($this->server->unreachable_notification_sent === false) {
            return;
        }
        GetContainersStatus::dispatch($server)->onQueue('high');
        // dispatch(new ContainerStatusJob($server));
    }

    public function via(object $notifiable): array
    {
        $channels = [];
        $isEmailEnabled = isEmailEnabled($notifiable);
        $isDiscordEnabled = data_get($notifiable, 'discord_enabled');
        $isTelegramEnabled = data_get($notifiable, 'telegram_enabled');

        if ($isDiscordEnabled) {
            $channels[] = DiscordChannel::class;
        }
        if ($isEmailEnabled) {
            $channels[] = EmailChannel::class;
        }
        if ($isTelegramEnabled) {
            $channels[] = TelegramChannel::class;
        }
        $executed = RateLimiter::attempt(
            'notification-server-revived-'.$this->server->uuid,
            1,
            function () use ($channels) {
                return $channels;
            },
            7200,
        );

        if (! $executed) {
            return [];
        }

        return $executed;
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject("Coolify: Server ({$this->server->name}) revived.");
        $mail->view('emails.server-revived', [
            'name' => $this->server->name,
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        return new DiscordMessage(
            title: ":white_check_mark: Server '{$this->server->name}' revived",
            description: 'All automations & integrations are turned on again!',
            color: DiscordMessage::successColor(),
        );
    }

    public function toTelegram(): array
    {
        return [
            'message' => "Coolify: Server '{$this->server->name}' revived. All automations & integrations are turned on again!",
        ];
    }
}
