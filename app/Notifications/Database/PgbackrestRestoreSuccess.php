<?php

namespace App\Notifications\Database;

use App\Models\StandalonePostgresql;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

class PgbackrestRestoreSuccess extends CustomEmailNotification
{
    public string $name;

    public function __construct(public StandalonePostgresql $database, public ?string $backupLabel = null)
    {
        $this->onQueue('high');
        $this->name = $database->name;
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('backup_success');
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject("Coolify: pgBackRest restore completed for {$this->name}");
        $mail->view('emails.pgbackrest-restore-success', [
            'name' => $this->name,
            'backup_label' => $this->backupLabel ?? 'latest',
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        $message = new DiscordMessage(
            title: ':white_check_mark: pgBackRest restore successful',
            description: "Database restore for {$this->name} completed successfully.",
            color: DiscordMessage::successColor(),
        );

        $message->addField('Backup Label', $this->backupLabel ?? 'latest', true);

        return $message;
    }

    public function toTelegram(): array
    {
        $label = $this->backupLabel ?? 'latest';
        $message = "Coolify: pgBackRest restore for {$this->name} from backup '{$label}' completed successfully.";

        return [
            'message' => $message,
        ];
    }

    public function toPushover(): PushoverMessage
    {
        $label = $this->backupLabel ?? 'latest';

        return new PushoverMessage(
            title: 'pgBackRest restore successful',
            level: 'success',
            message: "Database restore for {$this->name} completed successfully.<br/><br/><b>Backup Label:</b> {$label}",
        );
    }

    public function toSlack(): SlackMessage
    {
        $title = 'pgBackRest restore successful';
        $description = "Database restore for {$this->name} completed successfully.";
        $label = $this->backupLabel ?? 'latest';

        $description .= "\n\n*Backup Label:* {$label}";

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
            'message' => 'pgBackRest restore successful',
            'event' => 'pgbackrest_restore_success',
            'database_name' => $this->name,
            'database_uuid' => $this->database->uuid,
            'backup_label' => $this->backupLabel ?? 'latest',
            'url' => $url,
        ];
    }
}
