<?php

namespace App\Notifications\Node;

use App\Models\NodeCluster;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

class ClusterNetworkUnhealthy extends CustomEmailNotification
{
    /** @param list<string> $affectedNodeNames */
    public function __construct(public NodeCluster $cluster, public string $networkStatus, public array $affectedNodeNames = [])
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
        $mail->subject("Coolify: Cluster network ({$this->cluster->name}) is {$this->statusLabel()}.");
        $mail->view('emails.node-health-changed', [
            'description' => $this->description(),
            'details' => $this->affectedNodesLine(),
            'url' => $this->url(),
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        $message = new DiscordMessage(
            title: ':cross_mark: Cluster network '.$this->statusLabel(),
            description: $this->description(),
            color: DiscordMessage::errorColor(),
        );
        if ($this->affectedNodeNames !== []) {
            $message->addField('Affected Nodes', implode(', ', $this->affectedNodeNames));
        }
        $message->addField('Cluster', "[{$this->cluster->name}]({$this->url()})");

        return $message;
    }

    public function toTelegram(): array
    {
        return [
            'message' => trim("Coolify: {$this->description()}\n{$this->affectedNodesLine()}"),
            'buttons' => [
                ['text' => 'Open cluster', 'url' => $this->url()],
            ],
        ];
    }

    public function toPushover(): PushoverMessage
    {
        return new PushoverMessage(
            title: 'Cluster network '.$this->statusLabel(),
            level: 'error',
            message: trim("{$this->description()}<br/>{$this->affectedNodesLine()}"),
            buttons: ['Open cluster' => $this->url()],
        );
    }

    public function toSlack(): SlackMessage
    {
        return new SlackMessage(
            title: 'Cluster network '.$this->statusLabel(),
            description: trim("{$this->description()}\n{$this->affectedNodesLine()}")."\n{$this->url()}",
            color: SlackMessage::errorColor(),
        );
    }

    public function toWebhook(): array
    {
        return [
            'success' => false,
            'message' => 'Cluster network '.$this->statusLabel(),
            'event' => 'node_cluster_network_unhealthy',
            'cluster_name' => $this->cluster->name,
            'cluster_uuid' => $this->cluster->uuid,
            'network_status' => $this->networkStatus,
            'affected_nodes' => $this->affectedNodeNames,
            'url' => $this->url(),
        ];
    }

    private function statusLabel(): string
    {
        return $this->networkStatus === 'degraded' ? 'degraded' : 'failed';
    }

    private function description(): string
    {
        return "The private network of cluster '{$this->cluster->name}' is {$this->statusLabel()}. Workloads on its Nodes may not reach each other.";
    }

    private function affectedNodesLine(): string
    {
        return $this->affectedNodeNames === [] ? '' : 'Affected Nodes: '.implode(', ', $this->affectedNodeNames);
    }

    private function url(): string
    {
        return base_url().route('node-cluster.show', ['cluster_uuid' => $this->cluster->uuid], false);
    }
}
