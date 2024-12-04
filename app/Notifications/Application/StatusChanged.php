<?php

namespace App\Notifications\Application;

use App\Models\Application;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use Illuminate\Notifications\Messages\MailMessage;

class StatusChanged extends CustomEmailNotification
{
    public string $resource_name;

    public string $project_uuid;

    public string $environment_name;

    public ?string $resource_url = null;

    public ?string $fqdn;

    public function __construct(public Application $resource)
    {
        $this->onQueue('high');
        $this->resource_name = data_get($resource, 'name');
        $this->project_uuid = data_get($resource, 'environment.project.uuid');
        $this->environment_name = data_get($resource, 'environment.name');
        $this->fqdn = data_get($resource, 'fqdn', null);
        if (str($this->fqdn)->explode(',')->count() > 1) {
            $this->fqdn = str($this->fqdn)->explode(',')->first();
        }
        $this->resource_url = base_url()."/project/{$this->project_uuid}/".urlencode($this->environment_name)."/application/{$this->resource->uuid}";
    }

    public function via(object $notifiable): array
    {
        return setNotificationChannels($notifiable, 'status_changes');
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
}
