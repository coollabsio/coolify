<?php

namespace App\Notifications\Application;

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * A commit of a fork pull request waits for an admin approval. It uses the channels of the
 * deployment failure event, because the preview does not deploy until an admin acts.
 */
class PreviewApprovalRequired extends CustomEmailNotification
{
    public string $application_name;

    public string $commit;

    public string $previews_url;

    public string $pull_request_url;

    public string $deployment_url;

    public function __construct(public Application $application, public ApplicationPreview $preview, string $deployment_uuid)
    {
        $this->onQueue('high');
        $this->application_name = (string) data_get($application, 'name');
        $this->commit = (string) $preview->pending_approval_commit;
        $applicationUrl = base_url().'/project/'.data_get($application, 'environment.project.uuid').'/environment/'.data_get($application, 'environment.uuid')."/application/{$application->uuid}";
        $this->previews_url = "{$applicationUrl}/preview-deployments";
        $this->deployment_url = "{$applicationUrl}/deployment/{$deployment_uuid}";
        $this->pull_request_url = (string) $preview->pull_request_html_url;
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('deployment_failure');
    }

    public function toMail(?object $notifiable = null): MailMessage
    {
        return (new MailMessage)
            ->subject("Coolify: Pull request #{$this->preview->pull_request_id} of {$this->application_name} waits for approval")
            ->view('emails.application-preview-approval-required', [
                'name' => $this->application_name,
                'pull_request_id' => $this->preview->pull_request_id,
                'commit' => $this->commit,
                'pull_request_url' => $this->pull_request_url,
                'previews_url' => $this->previews_url,
            ]);
    }

    public function toDiscord(): DiscordMessage
    {
        $message = new DiscordMessage(
            title: ':raised_hand: Preview waits for approval',
            description: "Pull request #{$this->preview->pull_request_id} comes from a fork. An admin must approve commit {$this->commit}.",
            color: DiscordMessage::warningColor(),
        );
        $message->addField('Project', data_get($this->application, 'environment.project.name'), true);
        $message->addField('Environment', data_get($this->application, 'environment.name'), true);
        $message->addField('Name', $this->application_name, true);
        $message->addField('Approve', '[Link]('.$this->previews_url.')');

        return $message;
    }

    public function toTelegram(): array
    {
        return [
            'message' => "Coolify: Pull request #{$this->preview->pull_request_id} of {$this->application_name} comes from a fork. Commit {$this->commit} waits for an admin approval.",
            'buttons' => [
                ['text' => 'Approve', 'url' => $this->previews_url],
            ],
        ];
    }

    public function toPushover(): PushoverMessage
    {
        return new PushoverMessage(
            title: "Pull request #{$this->preview->pull_request_id} waits for approval",
            level: 'warning',
            message: "A fork pull request of {$this->application_name} waits for an admin approval of commit {$this->commit}.",
            buttons: [
                ['text' => 'Approve', 'url' => $this->previews_url],
            ],
        );
    }

    public function toSlack(): SlackMessage
    {
        $description = "Pull request #{$this->preview->pull_request_id} of {$this->application_name} comes from a fork. An admin must approve commit {$this->commit}.";
        $description .= "\n\n*Project:* ".data_get($this->application, 'environment.project.name');
        $description .= "\n*Environment:* ".data_get($this->application, 'environment.name');
        $description .= "\n*<{$this->previews_url}|Approve>*";

        return new SlackMessage(
            title: "Pull request #{$this->preview->pull_request_id} waits for approval",
            description: $description,
            color: SlackMessage::warningColor(),
        );
    }

    public function toWebhook(): array
    {
        return [
            'success' => false,
            'message' => 'Preview deployment waits for approval',
            'event' => 'preview_approval_required',
            'application_name' => $this->application_name,
            'application_uuid' => $this->application->uuid,
            'pull_request_id' => $this->preview->pull_request_id,
            'pull_request_url' => $this->pull_request_url,
            'commit' => $this->commit,
            'approve_url' => $this->previews_url,
            'deployment_url' => $this->deployment_url,
            'project' => data_get($this->application, 'environment.project.name'),
            'environment' => data_get($this->application, 'environment.name'),
        ];
    }
}
