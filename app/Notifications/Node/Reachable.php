<?php

namespace App\Notifications\Node;

use App\Models\Node;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

class Reachable extends CustomEmailNotification
{
    public function __construct(public Node $node)
    {
        $this->onQueue('high');
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('server_reachable');
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject("Coolify: Node ({$this->node->name}) is reachable again.");
        $mail->view('emails.node-health-changed', [
            'description' => $this->description(),
            'url' => $this->url(),
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        $message = new DiscordMessage(
            title: ':white_check_mark: Node reachable again',
            description: $this->description(),
            color: DiscordMessage::successColor(),
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
            title: 'Node reachable again',
            level: 'success',
            message: $this->description(),
            buttons: ['Open Node' => $this->url()],
        );
    }

    public function toSlack(): SlackMessage
    {
        return new SlackMessage(
            title: 'Node reachable again',
            description: "{$this->description()}\n{$this->url()}",
            color: SlackMessage::successColor(),
        );
    }

    public function toWebhook(): array
    {
        return [
            'success' => true,
            'message' => 'Node reachable again',
            'event' => 'node_reachable',
            'node_name' => $this->node->name,
            'node_uuid' => $this->node->uuid,
            'url' => $this->url(),
        ];
    }

    private function description(): string
    {
        return "Node '{$this->node->name}' is connected to Coolify again.";
    }

    private function url(): string
    {
        return base_url().route('node.show', ['node_uuid' => $this->node->uuid], false);
    }
}
