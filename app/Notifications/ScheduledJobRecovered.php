<?php

namespace App\Notifications;

use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Sent once on the first success after the team was notified that a scheduled job failed.
 * It goes to the channels of the failure notification, so everyone who got the failure learns that it works again.
 */
class ScheduledJobRecovered extends CustomEmailNotification
{
    /**
     * @param  string  $failureEvent  The notification setting of the failure, for example `backup_failure`.
     * @param  string  $webhookEvent  The `event` value of the webhook payload.
     * @param  array<string, mixed>  $webhookData  Extra fields of the webhook payload.
     */
    public function __construct(
        public string $failureEvent,
        public string $title,
        public string $webhookEvent,
        public ?string $url = null,
        public array $webhookData = [],
    ) {
        $this->onQueue('high');
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels($this->failureEvent);
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject("Coolify: {$this->title}");
        $mail->view('emails.scheduled-job-recovered', [
            'title' => $this->title,
            'url' => $this->url,
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        $message = new DiscordMessage(
            title: ':white_check_mark: '.$this->title,
            description: 'The last run succeeded after failed runs.',
            color: DiscordMessage::successColor(),
        );

        if ($this->url) {
            $message->addField('Link', '[Open in Coolify]('.$this->url.')');
        }

        return $message;
    }

    public function toTelegram(): array
    {
        return [
            'message' => "Coolify: {$this->title}\n\nThe last run succeeded after failed runs.",
        ];
    }

    public function toPushover(): PushoverMessage
    {
        return new PushoverMessage(
            title: $this->title,
            level: 'success',
            message: 'The last run succeeded after failed runs.',
            buttons: $this->url ? [['text' => 'Open in Coolify', 'url' => $this->url]] : [],
        );
    }

    public function toSlack(): SlackMessage
    {
        $description = 'The last run succeeded after failed runs.';
        if ($this->url) {
            $description .= "\n\n*Link:* {$this->url}";
        }

        return new SlackMessage(
            title: $this->title,
            description: $description,
            color: SlackMessage::successColor(),
        );
    }

    public function toWebhook(): array
    {
        return array_filter([
            'success' => true,
            'message' => $this->title,
            'event' => $this->webhookEvent,
            ...$this->webhookData,
            'url' => $this->url,
        ], fn ($value) => $value !== null);
    }
}
