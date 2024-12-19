<?php

namespace App\Notifications\Server;

use App\Models\Server;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

class Reachable extends CustomEmailNotification
{
    protected bool $isRateLimited = false;

    public function __construct(public Server $server)
    {
        $this->onQueue('high');
        $this->isRateLimited = isEmailRateLimited(
            limiterKey: 'server-reachable:'.$this->server->id,
        );
    }

    public function via(object $notifiable): array
    {
        if ($this->isRateLimited) {
            return [];
        }

        return $notifiable->getEnabledChannels('server_reachable');
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

    public function toPushover(): PushoverMessage
    {
        return new PushoverMessage(
            title: 'Server revived',
            message: "Server '{$this->server->name}' revived. All automations & integrations are turned on again!",
            level: 'success',
        );
    }

    public function toTelegram(): array
    {
        return [
            'message' => "Coolify: Server '{$this->server->name}' revived. All automations & integrations are turned on again!",
        ];
    }

    public function toSlack(): SlackMessage
    {
        return new SlackMessage(
            title: 'Server revived',
            description: "Server '{$this->server->name}' revived.\nAll automations & integrations are turned on again!",
            color: SlackMessage::successColor()
        );
    }
}
