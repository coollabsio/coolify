<?php

namespace App\Notifications\Notifications;

use App\Models\Application;
use App\Models\Team;
use App\Notifications\Channels\EmailChannel;
use App\Notifications\Channels\DiscordChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class ApplicationDeployedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    public Application $application;
    public string $application_name;
    public string $deployment_uuid;
    public string|null $deployment_url = null;
    public string $project_uuid;
    public string $environment_name;
    public string $fqdn;

    public function __construct(Application $application, string $deployment_uuid)
    {
        $this->application = $application;
        $this->application_name = data_get($application, 'name');
        $this->deployment_uuid = $deployment_uuid;
        $this->project_uuid = data_get($application, 'environment.project.uuid');
        $this->environment_name = data_get($application, 'environment.name');
        $this->fqdn = data_get($application, 'fqdn');
        if (Str::of($this->fqdn)->explode(',')->count() > 1) {
            $this->fqdn = Str::of($this->fqdn)->explode(',')->first();
        }
        $this->deployment_url =  base_url() . "/project/{$this->project_uuid}/{$this->environment_name}/application/{$this->application->uuid}/deployment/{$this->deployment_uuid}";
    }
    public function via(object $notifiable): array
    {
        $channels = [];
        if ($notifiable->extra_attributes?->get('email_active') && $notifiable->extra_attributes?->get('notifications_deployments')) {
            $channels[] = EmailChannel::class;
        }
        if ($notifiable->extra_attributes?->get('discord_active') && $notifiable->extra_attributes?->get('notifications_deployments')) {
            $channels[] = DiscordChannel::class;
        }
        return $channels;
    }
    public function toMail(Team $team): MailMessage
    {
        $mail = new MailMessage();
        $mail->subject("New version is deployed of {$this->application_name}");
        $mail->view('emails.application-deployed', [
            'name' => $this->application_name,
            'fqdn' => $this->fqdn,
            'url' => $this->deployment_url,
        ]);
        return $mail;
    }

    public function toDiscord(): string
    {
        return '⚒️ A new version has been deployed of **' . $this->application_name . '**.
[Application Link](' . $this->fqdn . ') | [Deployment logs](' . $this->deployment_url . ')';
    }
}
