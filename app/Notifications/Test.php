<?php

namespace App\Notifications;

use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\EmailChannel;
use App\Notifications\Channels\PushoverChannel;
use App\Notifications\Channels\SlackChannel;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Middleware\RateLimited;

class Test extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 5;

    public function __construct(public ?string $emails = null, public ?string $channel = null)
    {
        $this->onQueue('high');
    }

    public function via(object $notifiable): array
    {
        if ($this->channel) {
            $channels = match ($this->channel) {
                'email' => [EmailChannel::class],
                'discord' => [DiscordChannel::class],
                'telegram' => [TelegramChannel::class],
                'slack' => [SlackChannel::class],
                'pushover' => [PushoverChannel::class],
                default => [],
            };
        } else {
            $channels = $notifiable->getEnabledChannels('test');
        }

        return $channels;
    }

    public function middleware(object $notifiable, string $channel)
    {
        return match ($channel) {
            EmailChannel::class => [new RateLimited('email')],
            default => [],
        };
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject('Coolify: Test Email');
        $mail->view('emails.test');

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        $message = new DiscordMessage(
            title: ':white_check_mark: Test Success',
            description: 'This is a test Discord notification from Coolify. :cross_mark: :warning: :information_source:',
            color: DiscordMessage::successColor(),
        );

        $message->addField(name: 'Dashboard', value: '[Link]('.base_url().')', inline: true);

        return $message;
    }

    public function toTelegram(): array
    {
        return [
            'message' => 'Coolify: This is a test Telegram notification from Coolify.',
            'buttons' => [
                [
                    'text' => 'Go to your dashboard',
                    'url' => base_url(),
                ],
            ],
        ];
    }

    public function toPushover(): PushoverMessage
    {
        return new PushoverMessage(
            title: 'Test Pushover Notification',
            message: 'This is a test Pushover notification from Coolify.',
            buttons: [
                [
                    'text' => 'Go to your dashboard',
                    'url' => base_url(),
                ],
            ],
        );
    }

    public function toSlack(): SlackMessage
    {
        return new SlackMessage(
            title: 'Test Slack Notification',
            description: 'This is a test Slack notification from Coolify.'
        );
    }
}
