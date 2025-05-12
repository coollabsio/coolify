<?php

namespace App\Notifications\ScheduledTask;

use App\Models\ScheduledTask;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

class TaskFailed extends CustomEmailNotification
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
        return $notifiable->getEnabledChannels('scheduled_task_failure');
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject("Coolify: [ACTION REQUIRED] Scheduled task ({$this->task->name}) failed.");
        $mail->view('emails.scheduled-task-failed', [
            'task' => $this->task,
            'url' => $this->url,
            'output' => $this->output,
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        $message = new DiscordMessage(
            title: ':cross_mark: Scheduled task failed',
            description: "Scheduled task ({$this->task->name}) failed.",
            color: DiscordMessage::errorColor(),
        );

        if ($this->url) {
            $message->addField('Scheduled task', '[Link]('.$this->url.')');
        }

        return $message;
    }

    public function toTelegram(): array
    {
        $message = "Coolify: Scheduled task ({$this->task->name}) failed with output: {$this->output}";
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
        $message = "Scheduled task ({$this->task->name}) failed<br/>";

        if ($this->output) {
            $message .= "<br/><b>Error Output:</b>{$this->output}";
        }

        $buttons = [];
        if ($this->url) {
            $buttons[] = [
                'text' => 'Open task in Coolify',
                'url' => (string) $this->url,
            ];
        }

        return new PushoverMessage(
            title: 'Scheduled task failed',
            level: 'error',
            message: $message,
            buttons: $buttons,
        );
    }

    public function toSlack(): SlackMessage
    {
        $title = 'Scheduled task failed';
        $description = "Scheduled task ({$this->task->name}) failed.";

        if ($this->output) {
            $description .= "\n\n*Error Output:* {$this->output}";
        }

        if ($this->url) {
            $description .= "\n\n*Task URL:* {$this->url}";
        }

        return new SlackMessage(
            title: $title,
            description: $description,
            color: SlackMessage::errorColor()
        );
    }
}
