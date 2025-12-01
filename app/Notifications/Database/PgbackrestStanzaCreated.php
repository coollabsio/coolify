<?php

namespace App\Notifications\Database;

use App\Models\StandalonePostgresql;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

class PgbackrestStanzaCreated extends CustomEmailNotification
{
    public string $name;

    public string $stanzaName;

    public function __construct(public StandalonePostgresql $database)
    {
        $this->onQueue('high');
        $this->name = $database->name;
        $this->stanzaName = $database->getPgbackrestStanzaName();
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('backup_success');
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject("Coolify: pgBackRest stanza initialized for {$this->name}");
        $mail->view('emails.pgbackrest-stanza-created', [
            'name' => $this->name,
            'stanza_name' => $this->stanzaName,
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        $message = new DiscordMessage(
            title: ':white_check_mark: pgBackRest stanza initialized',
            description: "pgBackRest stanza for {$this->name} has been initialized successfully.",
            color: DiscordMessage::successColor(),
        );

        $message->addField('Stanza Name', $this->stanzaName, true);

        return $message;
    }

    public function toTelegram(): array
    {
        $message = "Coolify: pgBackRest stanza '{$this->stanzaName}' for {$this->name} has been initialized successfully.";

        return [
            'message' => $message,
        ];
    }

    public function toPushover(): PushoverMessage
    {
        return new PushoverMessage(
            title: 'pgBackRest stanza initialized',
            level: 'success',
            message: "pgBackRest stanza for {$this->name} has been initialized.<br/><br/><b>Stanza Name:</b> {$this->stanzaName}",
        );
    }

    public function toSlack(): SlackMessage
    {
        $title = 'pgBackRest stanza initialized';
        $description = "pgBackRest stanza for {$this->name} has been initialized successfully.";

        $description .= "\n\n*Stanza Name:* {$this->stanzaName}";

        return new SlackMessage(
            title: $title,
            description: $description,
            color: SlackMessage::successColor()
        );
    }

    public function toWebhook(): array
    {
        $url = base_url().'/project/'.data_get($this->database, 'environment.project.uuid').'/environment/'.data_get($this->database, 'environment.uuid').'/database/'.$this->database->uuid;

        return [
            'success' => true,
            'message' => 'pgBackRest stanza initialized',
            'event' => 'pgbackrest_stanza_created',
            'database_name' => $this->name,
            'database_uuid' => $this->database->uuid,
            'stanza_name' => $this->stanzaName,
            'url' => $url,
        ];
    }
}
