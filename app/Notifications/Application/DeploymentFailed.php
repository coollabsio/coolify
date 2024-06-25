<?php

namespace App\Notifications\Application;

use App\Models\Application;
use App\Models\ApplicationPreview;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DeploymentFailed extends Notification implements ShouldQueue
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

    public ?string $fqdn = null;

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
        return setNotificationChannels($notifiable, 'deployments');
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage();
        $pull_request_id = data_get($this->preview, 'pull_request_id', 0);
        $fqdn = $this->fqdn;
        if ($pull_request_id === 0) {
            $mail->subject('Coolify: Deployment failed of '.$this->application_name.'.');
        } else {
            $fqdn = $this->preview->fqdn;
            $mail->subject('Coolify: Deployment failed of pull request #'.$this->preview->pull_request_id.' of '.$this->application_name.'.');
        }
        $mail->view('emails.application-deployment-failed', [
            'name' => $this->application_name,
            'fqdn' => $fqdn,
            'deployment_url' => $this->deployment_url,
            'pull_request_id' => data_get($this->preview, 'pull_request_id', 0),
        ]);

        return $mail;
    }

    public function toDiscord(): string
    {
        if ($this->preview) {
            $message = 'Coolify:  Pull request #'.$this->preview->pull_request_id.' of '.$this->application_name.' ('.$this->preview->fqdn.') deployment failed: ';
            $message .= '[View Deployment Logs]('.$this->deployment_url.')';
        } else {
            $message = 'Coolify: Deployment failed of '.$this->application_name.' ('.$this->fqdn.'): ';
            $message .= '[View Deployment Logs]('.$this->deployment_url.')';
        }

        return $message;
    }

    public function toTelegram(): array
    {
        if ($this->preview) {
            $message = 'Coolify: Pull request #'.$this->preview->pull_request_id.' of '.$this->application_name.' ('.$this->preview->fqdn.') deployment failed: ';
        } else {
            $message = 'Coolify: Deployment failed of '.$this->application_name.' ('.$this->fqdn.'): ';
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
