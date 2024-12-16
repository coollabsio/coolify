<?php

namespace App\Notifications\Server;

use App\Models\Server;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

class Unreachable extends CustomEmailNotification
{
    protected bool $isRateLimited = false;

    public function __construct(public Server $server)
    {
        $this->onQueue('high');
        $this->isRateLimited = isEmailRateLimited(
            limiterKey: 'server-unreachable:'.$this->server->id,
        );
    }

    public function via(object $notifiable): array
    {
        if ($this->isRateLimited) {
            return [];
        }

        return $notifiable->getEnabledChannels('server_unreachable');
    }

    public function toMail(): ?MailMessage
    {
        $mail = new MailMessage;
        $mail->subject("Coolify: Your server ({$this->server->name}) is unreachable.");
        $mail->view('emails.server-lost-connection', [
            'name' => $this->server->name,
        ]);

        return $mail;
    }

    public function toDiscord(): ?DiscordMessage
    {
        $message = new DiscordMessage(
            title: ':cross_mark: Server unreachable',
            description: "Your server '{$this->server->name}' is unreachable.",
            color: DiscordMessage::errorColor(),
        );

        $message->addField('IMPORTANT', 'We automatically try to revive your server and turn on all automations & integrations.');

        return $message;
    }

    public function toTelegram(): ?array
    {
        return [
            'message' => "Coolify: Your server '{$this->server->name}' is unreachable. All automations & integrations are turned off! Please check your server! IMPORTANT: We automatically try to revive your server and turn on all automations & integrations.",
        ];
    }

    public function toPushover(): PushoverMessage
    {
        return new PushoverMessage(
            title: 'Server unreachable',
            level: 'error',
            message: "Your server '{$this->server->name}' is unreachable.<br/>All automations & integrations are turned off!<br/><br/><b>IMPORTANT:</b> We automatically try to revive your server and turn on all automations & integrations.",
        );
    }

    public function toSlack(): SlackMessage
    {
        $description = "Your server '{$this->server->name}' is unreachable.\n";
        $description .= "All automations & integrations are turned off!\n\n";
        $description .= '*IMPORTANT:* We automatically try to revive your server and turn on all automations & integrations.';

        return new SlackMessage(
            title: 'Server unreachable',
            description: $description,
            color: SlackMessage::errorColor()
        );
    }
}
