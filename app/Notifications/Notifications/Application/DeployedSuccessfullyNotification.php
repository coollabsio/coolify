<?php

namespace App\Notifications\Notifications\Application;

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Team;
use App\Notifications\Channels\EmailChannel;
use App\Notifications\Channels\DiscordChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class DeployedSuccessfullyNotification extends Notification implements ShouldQueue
{
    use Queueable;
    public Application $application;
    public string $deployment_uuid;
    public ApplicationPreview|null $preview = null;

    public string $application_name;
    public string|null $deployment_url = null;
    public string $project_uuid;
    public string $environment_name;
    public string $fqdn;

    public function __construct(Application $application, string $deployment_uuid, ApplicationPreview|null $preview = null)
    {
        $this->application = $application;
        $this->deployment_uuid = $deployment_uuid;
        $this->preview = $preview;

        $this->application_name = data_get($application, 'name');
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
        if ($notifiable->extra_attributes?->get('smtp_enabled') && $notifiable->extra_attributes?->get('notifications_email_deployments')) {
            $channels[] = EmailChannel::class;
        }
        if ($notifiable->extra_attributes?->get('discord_enabled') && $notifiable->extra_attributes?->get('notifications_discord_deployments')) {
            $channels[] = DiscordChannel::class;
        }
        return $channels;
    }
    public function toMail(Team $team): MailMessage
    {
        $mail = new MailMessage();
        $mail->subject("✅ New version is deployed of {$this->application_name}");
        $mail->view('emails.application-deployed-successfully', [
            'name' => $this->application_name,
            'fqdn' => $this->fqdn,
            'url' => $this->deployment_url,
            'pull_request_id' => $this->preview->pull_request_id,
        ]);
        return $mail;
    }

    public function toDiscord(): string
    {
        if ($this->preview) {
            $message = '✅ Pull request #' . $this->preview->pull_request_id . ' of **' . $this->application_name . '**. \n\n';
            $message .= '[Application Link](' . $this->preview->fqdn . ') | [Deployment logs](' . $this->deployment_url . ')';
        } else {
            $message = '✅ A new version has been deployed of **' . $this->application_name . '**. \n\n ';
            $message .= '[Application Link](' . $this->fqdn . ') | [Deployment logs](' . $this->deployment_url . ')';
        }
        return $message;
    }
}
