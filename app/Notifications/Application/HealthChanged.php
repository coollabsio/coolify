<?php

namespace App\Notifications\Application;

use App\Models\Application;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

class HealthChanged extends CustomEmailNotification
{
    public string $resource_name;

    public string $project_uuid;

    public string $environment_uuid;

    public string $environment_name;

    public ?string $resource_url = null;

    public ?string $fqdn;

    public bool $isHealthy;

    public function __construct(public Application $resource, public string $newStatus)
    {
        $this->onQueue('high');
        $this->resource_name = data_get($resource, 'name');
        $this->project_uuid = data_get($resource, 'environment.project.uuid');
        $this->environment_uuid = data_get($resource, 'environment.uuid');
        $this->environment_name = data_get($resource, 'environment.name');
        $this->fqdn = data_get($resource, 'fqdn', null);
        if (str($this->fqdn)->explode(',')->count() > 1) {
            $this->fqdn = str($this->fqdn)->explode(',')->first();
        }
        $this->resource_url = base_url()."/project/{$this->project_uuid}/environment/{$this->environment_uuid}/application/{$this->resource->uuid}";
        $this->isHealthy = str($newStatus)->contains(':healthy');
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('status_change');
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $status = $this->isHealthy ? 'healthy' : 'unhealthy';
        $mail->subject("Coolify: {$this->resource_name} is now {$status}");
        $mail->view('emails.application-health-changed', [
            'name' => $this->resource_name,
            'new_status' => $status,
            'fqdn' => $this->fqdn,
            'application_url' => $this->resource_url,
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        if ($this->isHealthy) {
            return new DiscordMessage(
                title: ':check_mark: Application healthy',
                description: "{$this->resource_name} has recovered.\n\n[Open Application in Coolify]({$this->resource_url})",
                color: DiscordMessage::successColor(),
            );
        }

        return new DiscordMessage(
            title: ':cross_mark: Application unhealthy',
            description: "{$this->resource_name} health check is failing.\n\n[Open Application in Coolify]({$this->resource_url})",
            color: DiscordMessage::errorColor(),
            isCritical: true,
        );
    }

    public function toTelegram(): array
    {
        $status = $this->isHealthy ? 'healthy' : 'unhealthy';
        $message = "Coolify: {$this->resource_name} is now {$status}.";

        return [
            'message' => $message,
            'buttons' => [
                [
                    'text' => 'Open Application in Coolify',
                    'url' => $this->resource_url,
                ],
            ],
        ];
    }

    public function toPushover(): PushoverMessage
    {
        $status = $this->isHealthy ? 'healthy' : 'unhealthy';

        return new PushoverMessage(
            title: "Application {$status}",
            level: $this->isHealthy ? 'info' : 'error',
            message: "{$this->resource_name} is now {$status}.",
            buttons: [
                [
                    'text' => 'Open Application in Coolify',
                    'url' => $this->resource_url,
                ],
            ],
        );
    }

    public function toSlack(): SlackMessage
    {
        $status = $this->isHealthy ? 'healthy' : 'unhealthy';
        $title = "Application {$status}";
        $description = "{$this->resource_name} health status changed to {$status}";

        $description .= "\n\n*Project:* " . data_get($this->resource, 'environment.project.name');
        $description .= "\n*Environment:* {$this->environment_name}";
        $description .= "\n*Application URL:* {$this->resource_url}";

        return new SlackMessage(
            title: $title,
            description: $description,
            color: $this->isHealthy ? SlackMessage::successColor() : SlackMessage::errorColor()
        );
    }

    public function toWebhook(): array
    {
        $status = $this->isHealthy ? 'healthy' : 'unhealthy';

        return [
            'success' => $this->isHealthy,
            'message' => "Application health changed to {$status}",
            'event' => 'health_changed',
            'application_name' => $this->resource_name,
            'application_uuid' => $this->resource->uuid,
            'url' => $this->resource_url,
            'project' => data_get($this->resource, 'environment.project.name'),
            'environment' => $this->environment_name,
            'fqdn' => $this->fqdn,
            'status' => $status,
        ];
    }
}
