<?php

namespace App\Notifications\Application;

use App\Models\Application;
use App\Models\ApplicationPreview;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DeploymentSuccess extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public Application $application;

    public ?ApplicationPreview $preview = null;

    public string $deployment_uuid;

    public string $application_name;

    public string $project_uuid;

    public string $environment_name;

    public ?string $deployment_url = null;

    public ?string $fqdn;

    public function __construct(Application $application, string $deployment_uuid, ?ApplicationPreview $preview = null)
    {
        $this->application = $application;
        $this->deployment_uuid = $deployment_uuid;
        $this->preview = $preview;
        $this->application_name = data_get($application, 'name');
        $this->project_uuid = data_get($application, 'environment.project.uuid');
        $this->environment_name = data_get($application, 'environment.name');
        $this->fqdn = data_get($application, 'fqdn');
        if (str($this->fqdn)->explode(',')->count() > 1) {
            $this->fqdn = str($this->fqdn)->explode(',')->first();
        }
        $this->deployment_url = base_url()."/project/{$this->project_uuid}/".urlencode($this->environment_name)."/application/{$this->application->uuid}/deployment/{$this->deployment_uuid}";
    }

    public function via(object $notifiable): array
    {
        $channels = setNotificationChannels($notifiable, 'deployments');
        if (isCloud()) {
            // TODO: Make batch notifications work with email
            $channels = array_diff($channels, ['App\Notifications\Channels\EmailChannel']);
        }

        return $channels;
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage();
        $pull_request_id = data_get($this->preview, 'pull_request_id', 0);
        $fqdn = $this->fqdn;
        if ($pull_request_id === 0) {
            $mail->subject("Coolify: New version is deployed of {$this->application_name}");
        } else {
            $fqdn = $this->preview->fqdn;
            $mail->subject("Coolify: Pull request #{$pull_request_id} of {$this->application_name} deployed successfully");
        }
        $mail->view('emails.application-deployment-success', [
            'name' => $this->application_name,
            'fqdn' => $fqdn,
            'deployment_url' => $this->deployment_url,
            'pull_request_id' => $pull_request_id,
        ]);

        return $mail;
    }

    public function toDiscord(): string
    {
        if ($this->preview) {
            $message = 'Coolify: New PR'.$this->preview->pull_request_id.' version successfully deployed of '.$this->application_name.'

';
            if ($this->preview->fqdn) {
                $message .= '[Open Application]('.$this->preview->fqdn.') | ';
            }
            $message .= '[Deployment logs]('.$this->deployment_url.')';
        } else {
            $message = 'Coolify: New version successfully deployed of '.$this->application_name.'

';
            if ($this->fqdn) {
                $message .= '[Open Application]('.$this->fqdn.') | ';
            }
            $message .= '[Deployment logs]('.$this->deployment_url.')';
        }

        return $message;
    }

    public function toTelegram(): array
    {
        if ($this->preview) {
            $message = 'Coolify: New PR'.$this->preview->pull_request_id.' version successfully deployed of '.$this->application_name.'';
            if ($this->preview->fqdn) {
                $buttons[] = [
                    'text' => 'Open Application',
                    'url' => $this->preview->fqdn,
                ];
            }
        } else {
            $message = '✅ New version successfully deployed of '.$this->application_name.'';
            if ($this->fqdn) {
                $buttons[] = [
                    'text' => 'Open Application',
                    'url' => $this->fqdn,
                ];
            }
        }
        $buttons[] = [
            'text' => 'Deployment logs',
            'url' => $this->deployment_url,
        ];

        return [
            'message' => $message,
            'buttons' => [
                ...$buttons,
            ],
        ];
    }
}
