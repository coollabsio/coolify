<?php

namespace Illuminate\Notifications;

use App\Notifications\Channels\SendsEmail;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

interface Notification
{
    public function toMail(SendsEmail $notifiable): MailMessage;

    public function toPushover(): PushoverMessage;

    public function toDiscord(): DiscordMessage;

    public function toSlack(): SlackMessage;

    public function toTelegram();
}
