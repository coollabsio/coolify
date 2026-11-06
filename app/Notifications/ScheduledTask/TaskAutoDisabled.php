<?php

namespace App\Notifications\ScheduledTask;

use App\Models\Application;
use App\Models\ScheduledTask;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

class TaskAutoDisabled extends CustomEmailNotification
{
    public ?string $url = null;

    public string $summary;

    public function __construct(public ScheduledTask $task)
    {
        $this->onQueue('high');
        $resource = $task->application ?? $task->service;
        if ($resource) {
            $type = $resource instanceof Application ? 'application' : 'service';
            $this->url = base_url().'/project/'.data_get($resource, 'environment.project.uuid').'/environment/'.data_get($resource, 'environment.uuid')."/{$type}/{$resource->uuid}/tasks/{$task->uuid}";
        }

        $this->summary = "Scheduled task ({$task->name}) was disabled after {$task->failure_count} failed runs in a row since {$task->failing_since?->toDayDateTimeString()} UTC. Fix the task and enable it again.";
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('scheduled_task_failure');
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject("Coolify: [ACTION REQUIRED] Scheduled task ({$this->task->name}) was disabled.");
        $mail->view('emails.scheduled-task-auto-disabled', [
            'summary' => $this->summary,
            'url' => $this->url,
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        $message = new DiscordMessage(
            title: ':no_entry: Scheduled task disabled',
            description: $this->summary,
            color: DiscordMessage::errorColor(),
        );

        if ($this->url) {
            $message->addField('Scheduled task', '[Link]('.$this->url.')');
        }

        return $message;
    }

    public function toTelegram(): array
    {
        return [
            'message' => "Coolify: {$this->summary}",
        ];
    }

    public function toPushover(): PushoverMessage
    {
        $buttons = [];
        if ($this->url) {
            $buttons[] = [
                'text' => 'Open task in Coolify',
                'url' => (string) $this->url,
            ];
        }

        return new PushoverMessage(
            title: 'Scheduled task disabled',
            level: 'error',
            message: $this->summary,
            buttons: $buttons,
        );
    }

    public function toSlack(): SlackMessage
    {
        $description = $this->summary;
        if ($this->url) {
            $description .= "\n\n*Task URL:* {$this->url}";
        }

        return new SlackMessage(
            title: 'Scheduled task disabled',
            description: $description,
            color: SlackMessage::errorColor()
        );
    }

    public function toWebhook(): array
    {
        $data = [
            'success' => false,
            'message' => 'Scheduled task disabled',
            'event' => 'task_auto_disabled',
            'task_name' => $this->task->name,
            'task_uuid' => $this->task->uuid,
            'failure_count' => $this->task->failure_count,
            'failing_since' => $this->task->failing_since?->toIso8601String(),
        ];

        if ($this->task->application) {
            $data['application_uuid'] = $this->task->application->uuid;
        } elseif ($this->task->service) {
            $data['service_uuid'] = $this->task->service->uuid;
        }

        if ($this->url) {
            $data['url'] = $this->url;
        }

        return $data;
    }
}
