<?php

namespace App\Notifications\ScheduledTask;

use App\Models\ScheduledTask;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

class TaskSuccess extends CustomEmailNotification
{
    public ?string $url = null;

    public function __construct(public ScheduledTask $scheduledTask, public string $output)
    {
        $this->onQueue('high');
        if ($scheduledTask->application) {
            $this->url = $scheduledTask->application->taskLink($scheduledTask->uuid);
        } elseif ($scheduledTask->service) {
            $this->url = $scheduledTask->service->taskLink($scheduledTask->uuid);
        }
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('scheduled_task_success');
    }

    public function toMail(): MailMessage
    {
        $mailMessage = new MailMessage;
        $mailMessage->subject("Coolify: Scheduled task ({$this->scheduledTask->name}) succeeded.");
        $mailMessage->view('emails.scheduled-task-success', [
            'task' => $this->scheduledTask,
            'url' => $this->url,
            'output' => $this->output,
        ]);

        return $mailMessage;
    }

    public function toDiscord(): DiscordMessage
    {
        $discordMessage = new DiscordMessage(
            title: ':white_check_mark: Scheduled task succeeded',
            description: "Scheduled task ({$this->scheduledTask->name}) succeeded.",
            color: DiscordMessage::successColor(),
        );

        if ($this->url) {
            $discordMessage->addField('Scheduled task', '[Link]('.$this->url.')');
        }

        return $discordMessage;
    }

    public function toTelegram(): array
    {
        $message = "Coolify: Scheduled task ({$this->scheduledTask->name}) succeeded.";
        if ($this->url) {
            $buttons[] = [
                'text' => 'Open task in Coolify',
                'url' => $this->url,
            ];
        }

        return [
            'message' => $message,
        ];
    }

    public function toPushover(): PushoverMessage
    {
        $message = "Coolify: Scheduled task ({$this->scheduledTask->name}) succeeded.";
        $buttons = [];
        if ($this->url) {
            $buttons[] = [
                'text' => 'Open task in Coolify',
                'url' => $this->url,
            ];
        }

        return new PushoverMessage(
            title: 'Scheduled task succeeded',
            level: 'success',
            message: $message,
            buttons: $buttons,
        );
    }

    public function toSlack(): SlackMessage
    {
        $title = 'Scheduled task succeeded';
        $description = "Scheduled task ({$this->scheduledTask->name}) succeeded.";

        if ($this->url) {
            $description .= "\n\n**Task URL:** {$this->url}";
        }

        return new SlackMessage(
            title: $title,
            description: $description,
            color: SlackMessage::successColor()
        );
    }
}
