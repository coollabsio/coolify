<?php

namespace App\Notifications\Application;

use App\Contracts\ThrottledNotification;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\BaseModel;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * A resource (application, preview, service part, or database) stopped without a stop from Coolify.
 */
class StatusChanged extends CustomEmailNotification implements ThrottledNotification
{
    public string $resource_name;

    public ?string $project_name;

    public ?string $project_uuid;

    public ?string $environment_uuid;

    public ?string $environment_name;

    public ?string $resource_url = null;

    public ?string $fqdn;

    public function __construct(public BaseModel $resource)
    {
        $this->onQueue('high');
        $environment = data_get($resource, 'environment')
            ?? data_get($resource, 'application.environment')
            ?? data_get($resource, 'service.environment');
        $this->resource_name = $resource instanceof ApplicationPreview
            ? data_get($resource, 'application.name').' PR #'.$resource->pull_request_id
            : (string) data_get($resource, 'name');
        $this->project_name = data_get($environment, 'project.name');
        $this->project_uuid = data_get($environment, 'project.uuid');
        $this->environment_uuid = data_get($environment, 'uuid');
        $this->environment_name = data_get($environment, 'name');
        $this->fqdn = data_get($resource, 'fqdn', null);
        if (str($this->fqdn)->explode(',')->count() > 1) {
            $this->fqdn = str($this->fqdn)->explode(',')->first();
        }
        [$type, $uuid] = match (true) {
            $resource instanceof Application => ['application', $resource->uuid],
            $resource instanceof ApplicationPreview => ['application', data_get($resource, 'application.uuid')],
            $resource instanceof ServiceApplication, $resource instanceof ServiceDatabase => ['service', data_get($resource, 'service.uuid')],
            default => ['database', $resource->uuid],
        };
        $this->resource_url = base_url()."/project/{$this->project_uuid}/environment/{$this->environment_uuid}/{$type}/{$uuid}";
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('status_change');
    }

    public function throttleSubject(): ?Model
    {
        return $this->resource;
    }

    /**
     * A resource that stops again soon after it came back sends no second notification.
     */
    public function throttleIntervalMinutes(): int
    {
        return 15;
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject("Coolify: {$this->resource_name} has stopped");
        $mail->view('emails.application-status-changes', [
            'name' => $this->resource_name,
            'fqdn' => $this->fqdn,
            'resource_url' => $this->resource_url,
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        return new DiscordMessage(
            title: ':cross_mark: Resource stopped',
            description: "{$this->resource_name} has stopped.\n\n[Open Resource in Coolify]({$this->resource_url})",
            color: DiscordMessage::errorColor(),
            isCritical: true,
        );
    }

    public function toTelegram(): array
    {
        return [
            'message' => "Coolify: {$this->resource_name} has stopped.",
            'buttons' => [
                [
                    'text' => 'Open Resource in Coolify',
                    'url' => $this->resource_url,
                ],
            ],
        ];
    }

    public function toPushover(): PushoverMessage
    {
        return new PushoverMessage(
            title: 'Resource stopped',
            level: 'error',
            message: "{$this->resource_name} has stopped.",
            buttons: [
                [
                    'text' => 'Open Resource in Coolify',
                    'url' => $this->resource_url,
                ],
            ],
        );
    }

    public function toSlack(): SlackMessage
    {
        $description = "{$this->resource_name} has stopped";
        $description .= "\n\n*Project:* {$this->project_name}";
        $description .= "\n*Environment:* {$this->environment_name}";
        $description .= "\n*Resource URL:* {$this->resource_url}";

        return new SlackMessage(
            title: 'Resource stopped',
            description: $description,
            color: SlackMessage::errorColor()
        );
    }

    public function toWebhook(): array
    {
        return [
            'success' => false,
            'message' => 'Resource stopped',
            'event' => 'status_changed',
            'status' => 'exited',
            'application_name' => $this->resource_name,
            'application_uuid' => $this->resource->uuid,
            'resource_name' => $this->resource_name,
            'resource_uuid' => $this->resource->uuid,
            'url' => $this->resource_url,
            'project' => $this->project_name,
            'environment' => $this->environment_name,
            'fqdn' => $this->fqdn,
        ];
    }
}
