<?php

namespace App\Notifications\Database;

use App\Models\StandalonePostgresql;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

class DatabaseRestoreSuccess extends CustomEmailNotification
{
    public string $name;

    public ?string $label;

    public ?string $targetTime;

    public function __construct(
        public StandalonePostgresql $database,
        ?string $label = null,
        ?string $targetTime = null
    ) {
        $this->onQueue('high');

        $this->name = $database->name;
        $this->label = $label;
        $this->targetTime = $targetTime;
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('backup_success');
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject("Coolify: Database restore successful for {$this->name}");
        $mail->view('emails.database-restore-success', [
            'name' => $this->name,
            'label' => $this->label,
            'target_time' => $this->targetTime,
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        $description = "Database restore for {$this->name} was successful.";
        if ($this->label) {
            $description .= " Restored from backup: {$this->label}";
        }

        $message = new DiscordMessage(
            title: ':white_check_mark: Database restore successful',
            description: $description,
            color: DiscordMessage::successColor(),
        );

        if ($this->targetTime) {
            $message->addField('Target Time', $this->targetTime, true);
        }

        return $message;
    }

    public function toTelegram(): array
    {
        $message = "Coolify: Database restore for {$this->name} was successful.";
        if ($this->label) {
            $message .= " Restored from backup: {$this->label}";
        }

        return [
            'message' => $message,
        ];
    }

    public function toPushover(): PushoverMessage
    {
        $message = "Database restore for {$this->name} was successful.";
        if ($this->label) {
            $message .= "<br/><b>Backup:</b> {$this->label}";
        }

        return new PushoverMessage(
            title: 'Database restore successful',
            level: 'success',
            message: $message,
        );
    }

    public function toSlack(): SlackMessage
    {
        $title = 'Database restore successful';
        $description = "Database restore for {$this->name} was successful.";

        if ($this->label) {
            $description .= "\n\n*Backup:* {$this->label}";
        }
        if ($this->targetTime) {
            $description .= "\n*Target Time:* {$this->targetTime}";
        }

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
            'message' => 'Database restore successful',
            'event' => 'restore_success',
            'database_name' => $this->name,
            'database_uuid' => $this->database->uuid,
            'engine' => 'pgbackrest',
            'backup_label' => $this->label,
            'target_time' => $this->targetTime,
            'url' => $url,
        ];
    }
}
