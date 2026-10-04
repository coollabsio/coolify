<?php

namespace App\Notifications\Node;

use App\Models\Node;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

class Unreachable extends CustomEmailNotification
{
    public function __construct(public Node $node)
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
        $mail->subject("Coolify: Node ({$this->node->name}) is unreachable.");
        $mail->view('emails.node-health-changed', [
            'description' => $this->description(),
            'url' => $this->url(),
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        $message = new DiscordMessage(
            title: ':cross_mark: Node unreachable',
            description: $this->description(),
            color: DiscordMessage::errorColor(),
        );
        $message->addField('Node', "[{$this->node->name}]({$this->url()})");

        return $message;
    }

    public function toTelegram(): array
    {
        return [
            'message' => "Coolify: {$this->description()}",
            'buttons' => [
                ['text' => 'Open Node', 'url' => $this->url()],
            ],
        ];
    }

    public function toPushover(): PushoverMessage
    {
        return new PushoverMessage(
            title: 'Node unreachable',
            level: 'error',
            message: $this->description(),
            buttons: ['Open Node' => $this->url()],
        );
    }

    public function toSlack(): SlackMessage
    {
        return new SlackMessage(
            title: 'Node unreachable',
            description: "{$this->description()}\n{$this->url()}",
            color: SlackMessage::errorColor(),
        );
    }

    public function toWebhook(): array
    {
        return [
            'success' => false,
            'message' => 'Node unreachable',
            'event' => 'node_unreachable',
            'node_name' => $this->node->name,
            'node_uuid' => $this->node->uuid,
            'url' => $this->url(),
        ];
    }

    private function description(): string
    {
        return "Node '{$this->node->name}' has not reported to Coolify for more than 3 minutes. Check that the machine and the Sentinel service are running.";
    }

    private function url(): string
    {
        return base_url().route('node.show', ['node_uuid' => $this->node->uuid], false);
    }
}
