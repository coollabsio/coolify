<?php

namespace App\Notifications\Database;

use App\Models\StandalonePostgresql;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

class PgbackrestRestoreFailed extends CustomEmailNotification
{
    public string $name;

    public function __construct(public StandalonePostgresql $database, public string $output, public ?string $backupLabel = null)
    {
        $this->onQueue('high');
        $this->name = $database->name;
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('backup_failure');
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject("Coolify: [ACTION REQUIRED] pgBackRest Restore FAILED for {$this->name}");
        $mail->view('emails.pgbackrest-restore-failed', [
            'name' => $this->name,
            'backup_label' => $this->backupLabel ?? 'latest',
            'output' => $this->output,
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        $message = new DiscordMessage(
            title: ':cross_mark: pgBackRest restore failed',
            description: "Database restore for {$this->name} has FAILED.",
            color: DiscordMessage::errorColor(),
            isCritical: true,
        );

        $message->addField('Backup Label', $this->backupLabel ?? 'latest', true);
        $message->addField('Error', $this->output);

        return $message;
    }

    public function toTelegram(): array
    {
        $label = $this->backupLabel ?? 'latest';
        $message = "Coolify: pgBackRest restore for {$this->name} from backup '{$label}' FAILED.\n\nReason:\n{$this->output}";

        return [
            'message' => $message,
        ];
    }

    public function toPushover(): PushoverMessage
    {
        $label = $this->backupLabel ?? 'latest';

        return new PushoverMessage(
            title: 'pgBackRest restore failed',
            level: 'error',
            message: "Database restore for {$this->name} FAILED.<br/><br/><b>Backup Label:</b> {$label}<br/><b>Error:</b> {$this->output}",
        );
    }

    public function toSlack(): SlackMessage
    {
        $title = 'pgBackRest restore failed';
        $description = "Database restore for {$this->name} has FAILED.";
        $label = $this->backupLabel ?? 'latest';

        $description .= "\n\n*Backup Label:* {$label}";
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
            'message' => 'pgBackRest restore failed',
            'event' => 'pgbackrest_restore_failed',
            'database_name' => $this->name,
            'database_uuid' => $this->database->uuid,
            'backup_label' => $this->backupLabel ?? 'latest',
            'error_output' => $this->output,
            'url' => $url,
        ];
    }
}
