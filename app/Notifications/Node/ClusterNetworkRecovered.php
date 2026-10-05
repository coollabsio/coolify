<?php

namespace App\Notifications\Node;

use App\Models\NodeCluster;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

class ClusterNetworkRecovered extends CustomEmailNotification
{
    public function __construct(public NodeCluster $cluster)
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
        $mail->subject("Coolify: Cluster network ({$this->cluster->name}) recovered.");
        $mail->view('emails.node-health-changed', [
            'description' => $this->description(),
            'url' => $this->url(),
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        $message = new DiscordMessage(
            title: ':white_check_mark: Cluster network recovered',
            description: $this->description(),
            color: DiscordMessage::successColor(),
        );
        $message->addField('Cluster', "[{$this->cluster->name}]({$this->url()})");

        return $message;
    }

    public function toTelegram(): array
    {
        return [
            'message' => "Coolify: {$this->description()}",
            'buttons' => [
                ['text' => 'Open cluster', 'url' => $this->url()],
            ],
        ];
    }

    public function toPushover(): PushoverMessage
    {
        return new PushoverMessage(
            title: 'Cluster network recovered',
            level: 'success',
            message: $this->description(),
            buttons: ['Open cluster' => $this->url()],
        );
    }

    public function toSlack(): SlackMessage
    {
        return new SlackMessage(
            title: 'Cluster network recovered',
            description: "{$this->description()}\n{$this->url()}",
            color: SlackMessage::successColor(),
        );
    }

    public function toWebhook(): array
    {
        return [
            'success' => true,
            'message' => 'Cluster network recovered',
            'event' => 'cluster_network_recovered',
            'cluster_name' => $this->cluster->name,
            'cluster_uuid' => $this->cluster->uuid,
            'url' => $this->url(),
        ];
    }

    private function description(): string
    {
        return "The private network of cluster '{$this->cluster->name}' is active again.";
    }

    private function url(): string
    {
        return base_url().route('node-cluster.show', ['cluster_uuid' => $this->cluster->uuid], false);
    }
}
