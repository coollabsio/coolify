<?php

namespace App\Notifications\Node;

use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

/** A build or deploy of a cluster application, preview, or stack failed, like v4 DeploymentFailed. */
class ClusterDeploymentFailed extends ClusterDeploymentNotification
{
    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('deployment_failure');
    }

    public function toMail(?object $notifiable = null): MailMessage
    {
        $mail = new MailMessage;
        if ($this->isPreview()) {
            $mail->subject('Coolify: Deployment failed of pull request #'.$this->pull_request_id.' of '.$this->application_name.'.');
        } else {
            $mail->subject('Coolify: Deployment failed of '.$this->application_name.'.');
        }
        $mail->view('emails.application-deployment-failed', [
            'commit' => $this->deploymentCommitDetailsForMail($notifiable),
            'name' => $this->application_name,
            'fqdn' => $this->isPreview() ? $this->preview_fqdn : $this->fqdn,
            'deployment_url' => $this->deployment_url,
            'pull_request_id' => $this->pull_request_id ?? 0,
            'blocked' => null,
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        $message = new DiscordMessage(
            title: ':cross_mark: Deployment failed',
            description: $this->isPreview() ? 'Pull request: '.$this->pull_request_id : ($this->fqdn ? '[Open application]('.$this->fqdn.')' : ''),
            color: DiscordMessage::errorColor(),
            isCritical: true,
        );
        $message->addField('Project', (string) $this->project_name, true);
        $message->addField('Environment', (string) $this->environment_name, true);
        $message->addField('Name', $this->application_name, true);
        $message->addField('Deployment Logs', '[Link]('.$this->deployment_url.')');
        if ($this->isPreview() && $this->fqdn) {
            $message->addField('Domain', $this->fqdn, true);
        }

        return $message;
    }

    public function toTelegram(): array
    {
        $message = $this->isPreview()
            ? 'Coolify: Pull request #'.$this->pull_request_id.' of '.$this->application_name.' ('.$this->preview_fqdn.') deployment failed: '
            : 'Coolify: Deployment failed of '.$this->application_name.' ('.$this->fqdn.'): ';

        return [
            'message' => $message,
            'buttons' => [['text' => 'Deployment logs', 'url' => $this->deployment_url]],
        ];
    }

    public function toPushover(): PushoverMessage
    {
        if ($this->isPreview()) {
            $title = "Pull request #{$this->pull_request_id} deployment failed";
            $message = "Pull request deployment failed for {$this->application_name}";
        } else {
            $title = 'Deployment failed';
            $message = "Deployment failed for {$this->application_name}";
        }

        return new PushoverMessage(
            title: $title,
            level: 'error',
            message: $message,
            buttons: [['text' => 'Deployment logs', 'url' => $this->deployment_url]],
        );
    }

    public function toSlack(): SlackMessage
    {
        if ($this->isPreview()) {
            $title = "Pull request #{$this->pull_request_id} deployment failed";
            $description = "Pull request deployment failed for {$this->application_name}";
            if ($this->preview_fqdn) {
                $description .= "\nPreview URL: {$this->preview_fqdn}";
            }
        } else {
            $title = 'Deployment failed';
            $description = "Deployment failed for {$this->application_name}";
            if ($this->fqdn) {
                $description .= "\nApplication URL: {$this->fqdn}";
            }
        }
        $description .= "\n\n*Project:* {$this->project_name}";
        $description .= "\n*Environment:* {$this->environment_name}";
        $description .= "\n*<{$this->deployment_url}|Deployment Logs>*";

        return new SlackMessage(title: $title, description: $description, color: SlackMessage::errorColor());
    }

    public function toWebhook(): array
    {
        $data = [
            'success' => false,
            'message' => 'Deployment failed',
            'event' => 'deployment_failed',
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
