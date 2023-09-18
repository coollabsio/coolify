<?php

namespace App\Notifications\Application;

use App\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class StatusChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public Application $application;
    public string $application_name;
    public string $project_uuid;
    public string $environment_name;

    public ?string $application_url = null;
    public ?string $fqdn;

    public function __construct($application)
    {
        $this->application = $application;
        $this->application_name = data_get($application, 'name');
        $this->project_uuid = data_get($application, 'environment.project.uuid');
        $this->environment_name = data_get($application, 'environment.name');
        $this->fqdn = data_get($application, 'fqdn', null);
        if (Str::of($this->fqdn)->explode(',')->count() > 1) {
            $this->fqdn = Str::of($this->fqdn)->explode(',')->first();
        }
        $this->application_url = base_url() . "/project/{$this->project_uuid}/{$this->environment_name}/application/{$this->application->uuid}";
    }

    public function via(object $notifiable): array
    {
        return setNotificationChannels($notifiable, 'status_changes');
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage();
        $fqdn = $this->fqdn;
        $mail->subject("⛔ {$this->application_name} has been stopped");
        $mail->view('emails.application-status-changes', [
            'name' => $this->application_name,
            'fqdn' => $fqdn,
            'application_url' => $this->application_url,
        ]);
        return $mail;
    }

    public function toDiscord(): string
    {
        $message = '⛔ ' . $this->application_name . ' has been stopped.

';
        $message .= '[Open Application in Coolify](' . $this->application_url . ')';
        return $message;
    }
    public function toTelegram(): array
    {
        $message = '⛔ ' . $this->application_name . ' has been stopped.';
        return [
            "message" => $message,
            "buttons" => [
                [
                    "text" => "Open Application in Coolify",
                    "url" => $this->application_url
                ]
            ],
        ];
    }
}
