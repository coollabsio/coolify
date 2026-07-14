<?php

namespace App\Notifications\Application;

use App\Models\Application;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

class RestartLimitReached extends CustomEmailNotification
{
    public string $resource_name;

    public string $project_uuid;

    public string $environment_uuid;

    public string $environment_name;

    public ?string $resource_url = null;

    public ?string $fqdn;

    public int $restart_count;

    public int $max_restart_count;

    public function __construct(public Application $resource)
    {
        $this->onQueue('high');
        $this->afterCommit();
        $this->resource_name = data_get($resource, 'name');
        $this->project_uuid = data_get($resource, 'environment.project.uuid');
        $this->environment_uuid = data_get($resource, 'environment.uuid');
        $this->environment_name = data_get($resource, 'environment.name');
        $this->fqdn = data_get($resource, 'fqdn', null);
        $this->restart_count = $resource->restart_count;
        $this->max_restart_count = $resource->max_restart_count;
        if (str($this->fqdn)->explode(',')->count() > 1) {
            $this->fqdn = str($this->fqdn)->explode(',')->first();
        }
        $this->resource_url = $this->resource->link() ?? base_url()."/project/{$this->project_uuid}/environment/{$this->environment_uuid}/application/{$this->resource->uuid}";
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('status_change');
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject("Coolify: {$this->resource_name} stopped - restart limit reached ({$this->restart_count}/{$this->max_restart_count})");
        $mail->view('emails.application-restart-limit-reached', [
            'name' => $this->resource_name,
            'fqdn' => $this->fqdn,
            'resource_url' => $this->resource_url,
            'restart_count' => $this->restart_count,
            'max_restart_count' => $this->max_restart_count,
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        return new DiscordMessage(
            title: ':warning: Restart limit reached',
            description: "{$this->resource_name} has been stopped after {$this->restart_count} restarts (limit: {$this->max_restart_count}).\n\n[Open Application in Coolify]({$this->resource_url})",
            color: DiscordMessage::errorColor(),
            isCritical: true,
        );
    }

    public function toTelegram(): array
    {
        $message = "Coolify: {$this->resource_name} has been stopped after {$this->restart_count} restarts (limit: {$this->max_restart_count}).";

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
        $message = "{$this->resource_name} has been stopped after {$this->restart_count} restarts (limit: {$this->max_restart_count}).";

        return new PushoverMessage(
            title: 'Restart limit reached',
            level: 'error',
            message: $message,
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
        $title = 'Restart limit reached';
        $description = "{$this->resource_name} has been stopped after {$this->restart_count} restarts (limit: {$this->max_restart_count})";

        $description .= "\n\n*Project:* ".data_get($this->resource, 'environment.project.name');
        $description .= "\n*Environment:* {$this->environment_name}";
        $description .= "\n*Application URL:* {$this->resource_url}";

        return new SlackMessage(
            title: $title,
            description: $description,
            color: SlackMessage::errorColor()
        );
    }

    public function toWebhook(): array
    {
        return [
            'success' => false,
            'message' => 'Restart limit reached',
            'event' => 'restart_limit_reached',
            'application_name' => $this->resource_name,
            'application_uuid' => $this->resource->uuid,
            'restart_count' => $this->restart_count,
            'max_restart_count' => $this->max_restart_count,
            'url' => $this->resource_url,
            'project' => data_get($this->resource, 'environment.project.name'),
            'environment' => $this->environment_name,
            'fqdn' => $this->fqdn,
        ];
    }
}
