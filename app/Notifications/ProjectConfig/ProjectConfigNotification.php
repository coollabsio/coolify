<?php

namespace App\Notifications\ProjectConfig;

use App\Models\EnvironmentConfigRevision;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * A coolify.json event of one environment. Messages hold key names and errors, never values.
 */
abstract class ProjectConfigNotification extends CustomEmailNotification
{
    public const EVENT = 'project_config';

    public string $project;

    public string $environment;

    public string $url;

    public function __construct(public EnvironmentConfigRevision $revision)
    {
        $this->onQueue('high');
        $this->afterCommit();
        $environment = $revision->link->environment;
        $this->project = (string) $environment->project->name;
        $this->environment = (string) $environment->name;
        $this->url = base_url()."/project/{$environment->project->uuid}/environment/{$environment->uuid}/configuration";
    }

    abstract protected function title(): string;

    abstract protected function message(): string;

    abstract protected function isError(): bool;

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels(self::EVENT);
    }

    protected function summary(): string
    {
        $commit = $this->revision->commit_sha ? ' (commit '.substr($this->revision->commit_sha, 0, 7).')' : '';

        return "{$this->project} / {$this->environment}{$commit}: {$this->message()}";
    }

    public function toMail(): MailMessage
    {
        return (new MailMessage)
            ->subject("Coolify: {$this->title()} - {$this->project} / {$this->environment}")
            ->line($this->summary())
            ->action('Open the Configuration tab', $this->url);
    }

    public function toDiscord(): DiscordMessage
    {
        return new DiscordMessage(
            title: $this->title(),
            description: $this->summary()."\n\n[Open in Coolify]({$this->url})",
            color: $this->isError() ? DiscordMessage::errorColor() : DiscordMessage::warningColor(),
            isCritical: $this->isError(),
        );
    }

    public function toTelegram(): array
    {
        return [
            'message' => "Coolify: {$this->title()}. {$this->summary()}",
            'buttons' => [['text' => 'Open in Coolify', 'url' => $this->url]],
        ];
    }

    public function toPushover(): PushoverMessage
    {
        return new PushoverMessage(
            title: $this->title(),
            level: $this->isError() ? 'error' : 'warning',
            message: $this->summary(),
            buttons: [['text' => 'Open in Coolify', 'url' => $this->url]],
        );
    }

    public function toSlack(): SlackMessage
    {
        return new SlackMessage(
            title: $this->title(),
            description: $this->summary()."\n\n*URL:* {$this->url}",
            color: $this->isError() ? SlackMessage::errorColor() : SlackMessage::warningColor(),
        );
    }

    public function toWebhook(): array
    {
        return [
            'success' => ! $this->isError(),
            'message' => $this->title(),
            'event' => static::WEBHOOK_EVENT,
            'project' => $this->project,
            'environment' => $this->environment,
            'revision_uuid' => $this->revision->uuid,
            'commit_sha' => $this->revision->commit_sha,
            'details' => $this->message(),
            'url' => $this->url,
        ];
    }
}
