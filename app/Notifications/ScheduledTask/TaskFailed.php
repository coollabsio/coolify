<?php

namespace App\Notifications\ScheduledTask;

use App\Models\ScheduledTask;
use App\Notifications\Dto\DiscordMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TaskFailed extends Notification implements ShouldQueue
{
    use Queueable;

    public $backoff = 10;

    public $tries = 2;

    public ?string $url = null;

    public function __construct(public ScheduledTask $task, public string $output)
    {
        if ($task->application) {
            $this->url = $task->application->failedTaskLink($task->uuid);
        } elseif ($task->service) {
            $this->url = $task->service->failedTaskLink($task->uuid);
        }
    }

    public function via(object $notifiable): array
    {
        return setNotificationChannels($notifiable, 'scheduled_tasks');
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

    public function toNtfy(): array
    {
        return [
            'title' => 'Coolify: Scheduled task failed',
            'message' => "Coolify: Scheduled task ({$this->task->name}) failed with output: {$this->output}",
            'buttons' => 'view, Open task in Coolify, '.$this->url.';',
            'emoji' => 'warning',
        ];
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
}
