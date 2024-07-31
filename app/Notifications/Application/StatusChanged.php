<?php

namespace App\Notifications\Application;

use App\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StatusChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public string $resource_name;

    public string $project_uuid;

    public string $environment_name;

    public ?string $resource_url = null;

    public ?string $fqdn;

    public function __construct(public Application $resource)
    {
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

    public function toDiscord(): string
    {
        $message = 'Coolify: '.$this->resource_name.' has been stopped.

';
        $message .= '[Open Application in Coolify]('.$this->resource_url.')';

        return $message;
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
