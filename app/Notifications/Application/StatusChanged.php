<?php

namespace App\Notifications\Application;

use App\Models\Application;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

class StatusChanged extends CustomEmailNotification
{
    public string $resource_name;

    public string $project_uuid;

    public string $environment_uuid;

    public string $environment_name;

    public ?string $resource_url = null;

    public ?string $fqdn;

    public function __construct(public Application $resource)
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
        $this->resource_url = base_url()."/project/{$this->project_uuid}/environments/{$this->environment_uuid}/application/{$this->resource->uuid}";
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('status_change');
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $fqdn = $this->fqdn;
        $mail->subject("Coolify: {$this->resource_name} has been stopped");
        $mail->view('emails.application-status-changes', [
            'name' => $this->resource_name,
            'fqdn' => $fqdn,
            'resource_url' => $this->resource_url,
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        return new DiscordMessage(
            title: ':cross_mark: Application stopped',
            description: '[Open Application in Coolify]('.$this->resource_url.')',
            color: DiscordMessage::errorColor(),
            isCritical: true,
        );
    }

    public function toTelegram(): array
    {
        $message = 'Coolify: '.$this->resource_name.' has been stopped.';

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
        $message = $this->resource_name.' has been stopped.';

        return new PushoverMessage(
            title: 'Application stopped',
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
        $title = 'Application stopped';
        $description = "{$this->resource_name} has been stopped";

        $description .= "\n\n*Project:* ".data_get($this->resource, 'environment.project.name');
        $description .= "\n*Environment:* {$this->environment_name}";
        $description .= "\n*Application URL:* {$this->resource_url}";

        return new SlackMessage(
            title: $title,
            description: $description,
            color: SlackMessage::errorColor()
        );
    }
}
