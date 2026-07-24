<?php

namespace App\Notifications\Database;

use App\Models\StandalonePostgresql;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

class PostgresqlWalArchivingFailed extends CustomEmailNotification
{
    public function __construct(
        public StandalonePostgresql $database,
        public string $output,
    ) {
        $this->onQueue('high');
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('backup_failure');
    }

    public function toMail(): MailMessage
    {
        return (new MailMessage)
            ->subject("Coolify: [ACTION REQUIRED] PostgreSQL WAL archiving failed for {$this->database->name}")
            ->line("Point-in-time recovery WAL archiving failed for {$this->database->name}.")
            ->line($this->output)
            ->line('PostgreSQL can retain unarchived WAL until the data disk fills. Please resolve the archive failure promptly.')
            ->action('Open database', $this->databaseUrl());
    }

    public function toDiscord(): DiscordMessage
    {
        $message = new DiscordMessage(
            title: ':cross_mark: PostgreSQL WAL archiving failed',
            description: "Point-in-time recovery WAL archiving failed for {$this->database->name}.",
            color: DiscordMessage::errorColor(),
            isCritical: true,
        );
        $message->addField('Reason', $this->output);
        $message->addField('Risk', 'Unarchived WAL can fill the PostgreSQL data disk.');
        $message->addField('Database', '[Open database]('.$this->databaseUrl().')');

        return $message;
    }

    public function toTelegram(): array
    {
        return [
            'message' => "Coolify: PostgreSQL WAL archiving failed for {$this->database->name}.\n\nReason:\n{$this->output}\n\nUnarchived WAL can fill the PostgreSQL data disk.\n{$this->databaseUrl()}",
        ];
    }

    public function toPushover(): PushoverMessage
    {
        return new PushoverMessage(
            title: 'PostgreSQL WAL archiving failed',
            level: 'error',
            message: "Point-in-time recovery WAL archiving failed for {$this->database->name}.<br/><br/><b>Reason:</b> {$this->output}<br/><br/>Unarchived WAL can fill the PostgreSQL data disk.",
            buttons: ['Open database' => $this->databaseUrl()],
        );
    }

    public function toSlack(): SlackMessage
    {
        return new SlackMessage(
            title: 'PostgreSQL WAL archiving failed',
            description: "Point-in-time recovery WAL archiving failed for {$this->database->name}.\n\n*Reason:* {$this->output}\n\nUnarchived WAL can fill the PostgreSQL data disk.\n\n<{$this->databaseUrl()}|Open database>",
            color: SlackMessage::errorColor(),
        );
    }

    public function toWebhook(): array
    {
        return [
            'success' => false,
            'message' => 'PostgreSQL WAL archiving failed',
            'event' => 'postgresql_wal_archiving_failed',
            'database_name' => $this->database->name,
            'database_uuid' => $this->database->uuid,
            'error_output' => $this->output,
            'url' => $this->databaseUrl(),
        ];
    }

    private function databaseUrl(): string
    {
        return base_url().'/project/'.data_get($this->database, 'environment.project.uuid')
            .'/environment/'.data_get($this->database, 'environment.uuid')
            .'/database/'.$this->database->uuid;
    }
}
