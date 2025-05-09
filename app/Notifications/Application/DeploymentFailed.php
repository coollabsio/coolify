<?php

namespace App\Notifications\Application;

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

class DeploymentFailed extends CustomEmailNotification
{
    public Application $application;

    public ?ApplicationPreview $preview = null;

    public string $deployment_uuid;

    public string $application_name;

    public string $project_uuid;

    public string $environment_uuid;

    public string $environment_name;

    public ?string $deployment_url = null;

    public ?string $fqdn = null;

    public function __construct(Application $application, string $deployment_uuid, ?ApplicationPreview $preview = null)
    {
        $this->onQueue('high');
        $this->application = $application;
        $this->deployment_uuid = $deployment_uuid;
        $this->preview = $preview;
        $this->application_name = data_get($application, 'name');
        $this->project_uuid = data_get($application, 'environment.project.uuid');
        $this->environment_uuid = data_get($application, 'environment.uuid');
        $this->environment_name = data_get($application, 'environment.name');
        $this->fqdn = data_get($application, 'fqdn');
        if (str($this->fqdn)->explode(',')->count() > 1) {
            $this->fqdn = str($this->fqdn)->explode(',')->first();
        }
        $this->deployment_url = base_url()."/project/{$this->project_uuid}/environment/{$this->environment_uuid}/application/{$this->application->uuid}/deployment/{$this->deployment_uuid}";
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('deployment_failure');
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
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

    public function toDiscord(): DiscordMessage
    {
        if ($this->preview) {
            $message = new DiscordMessage(
                title: ':cross_mark: Deployment failed',
                description: 'Pull request: '.$this->preview->pull_request_id,
                color: DiscordMessage::errorColor(),
                isCritical: true,
            );

            $message->addField('Project', data_get($this->application, 'environment.project.name'), true);
            $message->addField('Environment', $this->environment_name, true);
            $message->addField('Name', $this->application_name, true);

            $message->addField('Deployment Logs', '[Link]('.$this->deployment_url.')');
            if ($this->fqdn) {
                $message->addField('Domain', $this->fqdn, true);
            }
        } else {
            if ($this->fqdn) {
                $description = '[Open application]('.$this->fqdn.')';
            } else {
                $description = '';
            }
            $message = new DiscordMessage(
                title: ':cross_mark: Deployment failed',
                description: $description,
                color: DiscordMessage::errorColor(),
                isCritical: true,
            );

            $message->addField('Project', data_get($this->application, 'environment.project.name'), true);
            $message->addField('Environment', $this->environment_name, true);
            $message->addField('Name', $this->application_name, true);

            $message->addField('Deployment Logs', '[Link]('.$this->deployment_url.')');
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

    public function toPushover(): PushoverMessage
    {
        if ($this->preview) {
            $title = "Pull request #{$this->preview->pull_request_id} deployment failed";
            $message = "Pull request deployment failed for {$this->application_name}";
        } else {
            $title = 'Deployment failed';
            $message = "Deployment failed for {$this->application_name}";
        }

        $buttons[] = [
            'text' => 'Deployment logs',
            'url' => $this->deployment_url,
        ];

        return new PushoverMessage(
            title: $title,
            level: 'error',
            message: $message,
            buttons: [
                ...$buttons,
            ],
        );
    }

    public function toSlack(): SlackMessage
    {
        if ($this->preview) {
            $title = "Pull request #{$this->preview->pull_request_id} deployment failed";
            $description = "Pull request deployment failed for {$this->application_name}";
            if ($this->preview->fqdn) {
                $description .= "\nPreview URL: {$this->preview->fqdn}";
            }
        } else {
            $title = 'Deployment failed';
            $description = "Deployment failed for {$this->application_name}";
            if ($this->fqdn) {
                $description .= "\nApplication URL: {$this->fqdn}";
            }
        }

        $description .= "\n\n*Project:* ".data_get($this->application, 'environment.project.name');
        $description .= "\n*Environment:* {$this->environment_name}";
        $description .= "\n*<{$this->deployment_url}|Deployment Logs>*";

        return new SlackMessage(
            title: $title,
            description: $description,
            color: SlackMessage::errorColor()
        );
    }
}
