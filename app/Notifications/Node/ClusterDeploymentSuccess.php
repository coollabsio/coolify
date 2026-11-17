<?php

namespace App\Notifications\Node;

use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

/** A deploy of a cluster application, preview, or stack succeeded, like v4 DeploymentSuccess. */
class ClusterDeploymentSuccess extends ClusterDeploymentNotification
{
    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('deployment_success');
    }

    public function toMail(?object $notifiable = null): MailMessage
    {
        $mail = new MailMessage;
        if ($this->isPreview()) {
            $mail->subject("Coolify: Pull request #{$this->pull_request_id} of {$this->application_name} deployed successfully");
        } else {
            $mail->subject("Coolify: New version is deployed of {$this->application_name}");
        }
        $mail->view('emails.application-deployment-success', [
            'commit' => $this->deploymentCommitDetailsForMail($notifiable),
            'name' => $this->application_name,
            'fqdn' => $this->isPreview() ? $this->preview_fqdn : $this->fqdn,
            'deployment_url' => $this->deployment_url,
            'pull_request_id' => $this->pull_request_id ?? 0,
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        if ($this->isPreview()) {
            $message = new DiscordMessage(
                title: ':white_check_mark: Preview deployment successful',
                description: 'Pull request: '.$this->pull_request_id,
                color: DiscordMessage::successColor(),
            );
            if ($this->preview_fqdn) {
                $message->addField('Application', '[Link]('.$this->preview_fqdn.')');
            }
        } else {
            $message = new DiscordMessage(
                title: ':white_check_mark: New version successfully deployed',
                description: $this->fqdn ? '[Open application]('.$this->fqdn.')' : '',
                color: DiscordMessage::successColor(),
            );
        }
        $message->addField('Project', (string) $this->project_name, true);
        $message->addField('Environment', (string) $this->environment_name, true);
        $message->addField('Name', $this->application_name, true);
        $message->addField('Deployment logs', '[Link]('.$this->deployment_url.')');

        return $message;
    }

    public function toTelegram(): array
    {
        $buttons = [];
        if ($this->isPreview()) {
            $message = 'Coolify: New PR'.$this->pull_request_id.' version successfully deployed of '.$this->application_name;
            $url = $this->preview_fqdn;
        } else {
            $message = '✅ New version successfully deployed of '.$this->application_name;
            $url = $this->fqdn;
        }
        if ($url) {
            $buttons[] = ['text' => 'Open Application', 'url' => $url];
        }
        $buttons[] = ['text' => 'Deployment logs', 'url' => $this->deployment_url];

        return ['message' => $message, 'buttons' => $buttons];
    }

    public function toPushover(): PushoverMessage
    {
        $buttons = [];
        if ($this->isPreview()) {
            $title = "Pull request #{$this->pull_request_id} successfully deployed";
            $message = 'New PR'.$this->pull_request_id.' version successfully deployed of '.$this->application_name;
            $url = $this->preview_fqdn;
        } else {
            $title = 'New version successfully deployed';
            $message = 'New version successfully deployed of '.$this->application_name;
            $url = $this->fqdn;
        }
        if ($url) {
            $buttons[] = ['text' => 'Open Application', 'url' => $url];
        }
        $buttons[] = ['text' => 'Deployment logs', 'url' => $this->deployment_url];

        return new PushoverMessage(title: $title, level: 'success', message: $message, buttons: $buttons);
    }

    public function toSlack(): SlackMessage
    {
        if ($this->isPreview()) {
            $title = "Pull request #{$this->pull_request_id} successfully deployed";
            $description = "New version successfully deployed for {$this->application_name}";
            if ($this->preview_fqdn) {
                $description .= "\nPreview URL: {$this->preview_fqdn}";
            }
        } else {
            $title = 'New version successfully deployed';
            $description = "New version successfully deployed for {$this->application_name}";
            if ($this->fqdn) {
                $description .= "\nApplication URL: {$this->fqdn}";
            }
        }
        $description .= "\n\n*Project:* {$this->project_name}";
        $description .= "\n*Environment:* {$this->environment_name}";
        $description .= "\n*<{$this->deployment_url}|Deployment Logs>*";

        return new SlackMessage(title: $title, description: $description, color: SlackMessage::successColor());
    }

    public function toWebhook(): array
    {
        $data = [
            'success' => true,
            'message' => 'New version successfully deployed',
            'event' => 'deployment_success',
            'application_name' => $this->application_name,
            'application_uuid' => $this->application_uuid,
            'deployment_uuid' => $this->deployment_uuid,
            'deployment_url' => $this->deployment_url,
            'project' => $this->project_name,
            'environment' => $this->environment_name,
        ];
        if ($this->isPreview()) {
            $data['pull_request_id'] = $this->pull_request_id;
            $data['preview_fqdn'] = $this->preview_fqdn;
        }
        if ($this->fqdn) {
            $data['fqdn'] = $this->fqdn;
        }

        return $data;
    }
}
