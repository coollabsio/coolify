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
        return $notifiable->getEnabledChannels('scheduled_task_failure');
    }

    public function toMail(): MailMessage
    {
        $mailMessage = new MailMessage;
        $mailMessage->subject("Coolify: [ACTION REQUIRED] Scheduled task ({$this->scheduledTask->name}) failed.");
        $mailMessage->view('emails.scheduled-task-failed', [
            'task' => $this->scheduledTask,
            'url' => $this->url,
            'output' => $this->output,
        ]);

        return $mailMessage;
    }

    public function toDiscord(): DiscordMessage
    {
        $discordMessage = new DiscordMessage(
            title: ':cross_mark: Scheduled task failed',
            description: "Scheduled task ({$this->scheduledTask->name}) failed.",
            color: DiscordMessage::errorColor(),
        );

        if ($this->url) {
            $discordMessage->addField('Scheduled task', '[Link]('.$this->url.')');
        }

        return $discordMessage;
    }

    public function toTelegram(): array
    {
        $message = "Coolify: Scheduled task ({$this->scheduledTask->name}) failed with output: {$this->output}";
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
        $message = "Scheduled task ({$this->scheduledTask->name}) failed<br/>";

        if ($this->output !== '' && $this->output !== '0') {
            $message .= "<br/><b>Error Output:</b>{$this->output}";
        }

        $buttons = [];
        if ($this->url) {
            $buttons[] = [
                'text' => 'Open task in Coolify',
                'url' => $this->url,
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
        $description = "Scheduled task ({$this->scheduledTask->name}) failed.";

        if ($this->output !== '' && $this->output !== '0') {
            $description .= "\n\n**Error Output:**\n{$this->output}";
        }

        if ($this->url) {
            $description .= "\n\n**Task URL:** {$this->url}";
        }

        return new SlackMessage(
            title: $title,
            description: $description,
            color: SlackMessage::errorColor()
        );
    }
}
