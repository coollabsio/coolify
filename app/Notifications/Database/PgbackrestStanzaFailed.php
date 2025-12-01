<?php

namespace App\Notifications\Database;

use App\Models\StandalonePostgresql;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

class PgbackrestStanzaFailed extends CustomEmailNotification
{
    public string $name;

    public string $stanzaName;

    public function __construct(public StandalonePostgresql $database, public string $output)
    {
        $this->onQueue('high');
        $this->name = $database->name;
        $this->stanzaName = $database->getPgbackrestStanzaName();
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('backup_failure');
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject("Coolify: [ACTION REQUIRED] pgBackRest Stanza FAILED for {$this->name}");
        $mail->view('emails.pgbackrest-stanza-failed', [
            'name' => $this->name,
            'stanza_name' => $this->stanzaName,
            'output' => $this->output,
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        $message = new DiscordMessage(
            title: ':cross_mark: pgBackRest stanza initialization failed',
            description: "pgBackRest stanza initialization for {$this->name} has FAILED.",
            color: DiscordMessage::errorColor(),
            isCritical: true,
        );

        $message->addField('Stanza Name', $this->stanzaName, true);
        $message->addField('Error', $this->output);

        return $message;
    }

    public function toTelegram(): array
    {
        $message = "Coolify: pgBackRest stanza '{$this->stanzaName}' for {$this->name} FAILED.\n\nReason:\n{$this->output}";

        return [
            'message' => $message,
        ];
    }

    public function toPushover(): PushoverMessage
    {
        return new PushoverMessage(
            title: 'pgBackRest stanza initialization failed',
            level: 'error',
            message: "pgBackRest stanza initialization for {$this->name} FAILED.<br/><br/><b>Stanza Name:</b> {$this->stanzaName}<br/><b>Error:</b> {$this->output}",
        );
    }

    public function toSlack(): SlackMessage
    {
        $title = 'pgBackRest stanza initialization failed';
        $description = "pgBackRest stanza initialization for {$this->name} has FAILED.";

        $description .= "\n\n*Stanza Name:* {$this->stanzaName}";
        $description .= "\n\n*Error:* {$this->output}";

        return new SlackMessage(
            title: $title,
            description: $description,
            color: SlackMessage::errorColor()
        );
    }

    public function toWebhook(): array
    {
        $url = base_url().'/project/'.data_get($this->database, 'environment.project.uuid').'/environment/'.data_get($this->database, 'environment.uuid').'/database/'.$this->database->uuid;

        return [
            'success' => false,
            'message' => 'pgBackRest stanza initialization failed',
            'event' => 'pgbackrest_stanza_failed',
            'database_name' => $this->name,
            'database_uuid' => $this->database->uuid,
            'stanza_name' => $this->stanzaName,
            'error_output' => $this->output,
            'url' => $url,
        ];
    }
}
