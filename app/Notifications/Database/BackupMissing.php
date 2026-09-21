<?php

namespace App\Notifications\Database;

use App\Models\ScheduledDatabaseBackup;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;

class BackupMissing extends CustomEmailNotification
{
    public string $databaseName;

    public function __construct(public ScheduledDatabaseBackup $backup, public ?Carbon $lastExecutionAt)
    {
        $this->onQueue('high');
        $this->databaseName = $backup->database?->name ?? $backup->description ?? $backup->uuid;
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('backup_failure');
    }

    public function toMail(): MailMessage
    {
        return (new MailMessage)
            ->subject("Coolify: [ACTION REQUIRED] No recent backup for {$this->databaseName}")
            ->view('emails.backup-missing', $this->messageData());
    }

    public function toDiscord(): DiscordMessage
    {
        return new DiscordMessage(
            title: ':warning: Scheduled database backup missing',
            description: $this->description(),
            color: DiscordMessage::errorColor(),
            isCritical: true,
        );
    }

    public function toTelegram(): array
    {
        return ['message' => 'Coolify: '.$this->description()];
    }

    public function toPushover(): PushoverMessage
    {
        return new PushoverMessage(title: 'Scheduled database backup missing', level: 'error', message: $this->description());
    }

    public function toSlack(): SlackMessage
    {
        return new SlackMessage(title: 'Scheduled database backup missing', description: $this->description(), color: SlackMessage::errorColor());
    }

    public function toWebhook(): array
    {
        return array_merge($this->messageData(), [
            'success' => false,
            'message' => 'Scheduled database backup missing',
            'event' => 'backup_missing',
            'backup_uuid' => $this->backup->uuid,
        ]);
    }

    private function description(): string
    {
        return "The enabled backup schedule for {$this->databaseName} has produced no executions in the last {$this->backup->missing_backup_notification_days} day(s).";
    }

    private function messageData(): array
    {
        return [
            'database_name' => $this->databaseName,
            'days' => $this->backup->missing_backup_notification_days,
            'last_execution_at' => $this->lastExecutionAt?->toDateTimeString(),
        ];
    }
}
