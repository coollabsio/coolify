<?php

namespace App\Notifications\ScheduledTask;

use App\Contracts\ThrottledNotification;
use App\Models\NotificationThrottle;
use App\Models\ScheduledTask;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Sent on the first failure of a streak, then at most once a day until the task succeeds again.
 * With $disabled, it tells that Coolify disabled the task, and it is always sent.
 */
class TaskFailed extends CustomEmailNotification implements ThrottledNotification
{
    public ?string $url = null;

    public function __construct(public ScheduledTask $task, public string $output, public bool $disabled = false)
    {
        $this->onQueue('high');
        $this->url = $task->url();
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('scheduled_task_failure');
    }

    public function throttleSubject(): ?Model
    {
        return $this->disabled ? null : $this->task;
    }

    public function throttleIntervalMinutes(): int
    {
        return NotificationThrottle::FAILURE_REMINDER_INTERVAL_MINUTES;
    }

    public function title(): string
    {
        return $this->disabled
            ? "Scheduled task ({$this->task->name}) was disabled because it keeps failing."
            : "Scheduled task ({$this->task->name}) failed.";
    }

    /**
     * Tells how long the task fails, and what happens next.
     */
    public function streakSummary(): string
    {
        if ($this->disabled) {
            return ($this->task->disabled_reason ?? 'Disabled automatically.').' Fix the task, then enable it again.';
        }

        $failures = (int) $this->task->consecutive_failures;
        $summary = $failures > 1 && $this->task->failing_since
            ? "It failed {$failures} times in a row since {$this->task->failing_since->toDateTimeString()} UTC. "
            : '';

        return $summary.'While it keeps failing, you get at most one notification each day. After '
            .ScheduledTask::AUTO_DISABLE_AFTER_FAILURES.' failures in a row over more than '
            .ScheduledTask::AUTO_DISABLE_AFTER_HOURS.' hours, Coolify disables the task.';
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject("Coolify: [ACTION REQUIRED] {$this->title()}");
        $mail->view('emails.scheduled-task-failed', [
            'task' => $this->task,
            'url' => $this->url,
            'output' => $this->output,
            'title' => $this->title(),
            'summary' => $this->streakSummary(),
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        $message = new DiscordMessage(
            title: $this->disabled ? ':cross_mark: Scheduled task disabled' : ':cross_mark: Scheduled task failed',
            description: $this->title()."\n\n".$this->streakSummary(),
            color: DiscordMessage::errorColor(),
        );

        if ($this->url) {
            $message->addField('Scheduled task', '[Link]('.$this->url.')');
        }

        return $message;
    }

    public function toTelegram(): array
    {
        $message = "Coolify: {$this->title()}\n\n{$this->streakSummary()}\n\nOutput: {$this->output}";
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
        $message = "{$this->title()}<br/>{$this->streakSummary()}<br/>";

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
            title: $this->disabled ? 'Scheduled task disabled' : 'Scheduled task failed',
            level: 'error',
            message: $message,
            buttons: $buttons,
        );
    }

    public function toSlack(): SlackMessage
    {
        $title = $this->disabled ? 'Scheduled task disabled' : 'Scheduled task failed';
        $description = $this->title()."\n\n".$this->streakSummary();

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

    public function toWebhook(): array
    {
        $data = [
            'success' => false,
            'message' => $this->disabled ? 'Scheduled task disabled' : 'Scheduled task failed',
            'event' => $this->disabled ? 'task_disabled' : 'task_failed',
            'task_name' => $this->task->name,
            'task_uuid' => $this->task->uuid,
            'output' => $this->output,
            'consecutive_failures' => (int) $this->task->consecutive_failures,
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
