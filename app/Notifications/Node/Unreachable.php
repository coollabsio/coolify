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
    /** @var list<array{name: string, uuid: string}> The applications on the server when it went offline (decision 0014). */
    public array $applications;

    public function __construct(public Node $node)
    {
        $this->onQueue('high');
        $this->applications = $node->workloads()->orderBy('name')->get(['node_workloads.name', 'node_workloads.uuid'])
            ->map(fn ($workload): array => ['name' => $workload->name, 'uuid' => $workload->uuid])
            ->all();
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('server_unreachable');
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject("Coolify: Cluster server ({$this->node->name}) is unreachable.");
        $mail->view('emails.node-health-changed', [
            'description' => $this->description(),
            'url' => $this->url(),
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        $message = new DiscordMessage(
            title: ':cross_mark: Cluster server unreachable',
            description: $this->description(),
            color: DiscordMessage::errorColor(),
        );
        $message->addField('Server', "[{$this->node->name}]({$this->url()})");

        return $message;
    }

    public function toTelegram(): array
    {
        return [
            'message' => "Coolify: {$this->description()}",
            'buttons' => [
                ['text' => 'Open server', 'url' => $this->url()],
            ],
        ];
    }

    public function toPushover(): PushoverMessage
    {
        return new PushoverMessage(
            title: 'Cluster server unreachable',
            level: 'error',
            message: $this->description(),
            buttons: ['Open server' => $this->url()],
        );
    }

    public function toSlack(): SlackMessage
    {
        return new SlackMessage(
            title: 'Cluster server unreachable',
            description: "{$this->description()}\n{$this->url()}",
            color: SlackMessage::errorColor(),
        );
    }

    public function toWebhook(): array
    {
        return [
            'success' => false,
            'message' => 'Cluster server unreachable',
            'event' => 'server_unreachable',
            'server_name' => $this->node->name,
            'server_uuid' => $this->node->uuid,
            'server_type' => 'cluster',
            'url' => $this->url(),
            'affected_applications' => $this->applications,
        ];
    }

    private function description(): string
    {
        $description = "Cluster server '{$this->node->name}' has not reported to Coolify for more than 3 minutes. Check that the machine and the Sentinel service are running.";
        if ($this->applications === []) {
            return $description;
        }
        $names = array_column(array_slice($this->applications, 0, 10), 'name');
        $more = count($this->applications) - count($names);

        return $description.' Affected applications: '.implode(', ', $names).($more > 0 ? " and {$more} more" : '')
            .'. Coolify does not move them automatically. To recover one now, use Move to another server on its page.';
    }

    private function url(): string
    {
        return base_url().route('node.show', ['node_uuid' => $this->node->uuid], false);
    }
}
