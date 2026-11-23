<?php

namespace App\Notifications\Server;

use App\Models\Server;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Sent with the channels of the "server unreachable" event: a changed host key is a connection problem.
 */
class HostKeyChanged extends CustomEmailNotification
{
    public function __construct(public Server $server)
    {
        $this->onQueue('high');
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('server_unreachable');
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject("Coolify: The SSH host key of your server ({$this->server->name}) changed.");
        $mail->view('emails.server-host-key-changed', [
            'name' => $this->server->name,
            'text' => $this->text(),
            'url' => $this->url(),
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        $message = new DiscordMessage(
            title: ':warning: SSH host key changed',
            description: $this->text(),
            color: DiscordMessage::errorColor(),
        );
        $message->addField('Check the new key', '[Link]('.$this->url().')');

        return $message;
    }

    public function toTelegram(): array
    {
        return [
            'message' => 'Coolify: '.$this->text(),
            'buttons' => [['text' => 'Check the new key', 'url' => $this->url()]],
        ];
    }

    public function toPushover(): PushoverMessage
    {
        return new PushoverMessage(
            title: 'SSH host key changed',
            level: 'error',
            message: $this->text(),
            buttons: [['text' => 'Check the new key', 'url' => $this->url()]],
        );
    }

    public function toSlack(): SlackMessage
    {
        return new SlackMessage(
            title: 'SSH host key changed',
            description: $this->text()."\n\nCheck the new key: ".$this->url(),
            color: SlackMessage::errorColor()
        );
    }

    public function toWebhook(): array
    {
        return [
            'success' => false,
            'message' => 'SSH host key changed',
            'event' => 'server_ssh_host_key_changed',
            'server_name' => $this->server->name,
            'server_uuid' => $this->server->uuid,
            'url' => $this->url(),
        ];
    }

    private function text(): string
    {
        return "The SSH host key of your server '{$this->server->name}' does not match the key that Coolify trusts. "
            .'This occurs when the server was reinstalled, or when another machine answers at its address. '
            .'Compare the new key with the key on the server, then trust it or reset it.';
    }

    private function url(): string
    {
        return base_url().'/server/'.$this->server->uuid.'/security#server-host-key-section';
    }
}
