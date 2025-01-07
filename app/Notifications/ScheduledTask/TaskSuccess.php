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

    public function __construct(public ScheduledTask $task, public string $output)
    {
        $this->onQueue('high');
        if ($task->application) {
            $this->url = $task->application->taskLink($task->uuid);
        } elseif ($task->service) {
            $this->url = $task->service->taskLink($task->uuid);
        }
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('scheduled_task_success');
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject("Coolify: Scheduled task ({$this->task->name}) succeeded.");
        $mail->view('emails.scheduled-task-success', [
            'task' => $this->task,
            'url' => $this->url,
            'output' => $this->output,
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        $message = new DiscordMessage(
            title: ':white_check_mark: Scheduled task succeeded',
            description: "Scheduled task ({$this->task->name}) succeeded.",
            color: DiscordMessage::successColor(),
        );

        if ($this->url) {
            $message->addField('Scheduled task', '[Link]('.$this->url.')');
        }

        return $message;
    }

    public function toTelegram(): array
    {
        $message = "Coolify: Scheduled task ({$this->task->name}) succeeded.";
        if ($this->url) {
            $buttons[] = [
                'text' => 'Open task in Coolify',
                'url' => (string) $this->url,
            ];
        }

        return [
            'message' => $message,
        ];
    }

    public function toPushover(): PushoverMessage
    {
        $message = "Coolify: Scheduled task ({$this->task->name}) succeeded.";
        $buttons = [];
        if ($this->url) {
            $buttons[] = [
                'text' => 'Open task in Coolify',
                'url' => (string) $this->url,
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
        $description = "Scheduled task ({$this->task->name}) succeeded.";

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
