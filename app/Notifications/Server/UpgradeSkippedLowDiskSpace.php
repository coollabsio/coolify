<?php

namespace App\Notifications\Server;

use App\Contracts\ThrottledNotification;
use App\Models\Server;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use App\Services\CoolifyUpgradeDiskSpace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

class UpgradeSkippedLowDiskSpace extends CustomEmailNotification implements ThrottledNotification
{
    public int $requiredGb = CoolifyUpgradeDiskSpace::REQUIRED_GB;

    public function __construct(public Server $server, public string $version, public float $availableGb)
    {
        $this->onQueue('high');
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('server_disk_usage');
    }

    public function throttleSubject(): ?Model
    {
        return $this->server;
    }

    public function throttleIntervalMinutes(): int
    {
        return 24 * 60;
    }

    private function cleanupUrl(): string
    {
        return base_url().'/server/'.$this->server->uuid.'/docker-cleanup';
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject("Coolify: Automatic update to {$this->version} skipped, not enough free disk space");
        $mail->view('emails.upgrade-skipped-low-disk-space', [
            'version' => $this->version,
            'available_gb' => $this->availableGb,
            'required_gb' => $this->requiredGb,
            'cleanup_url' => $this->cleanupUrl(),
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        $message = new DiscordMessage(
            title: ':warning: Automatic update skipped',
            description: "Coolify did not update to {$this->version} because the server does not have enough free disk space.",
            color: DiscordMessage::warningColor(),
            isCritical: true,
        );

        $message->addField('Free', "{$this->availableGb} GB", true);
        $message->addField('Required', "{$this->requiredGb} GB", true);
        $message->addField('What to do?', '[Docker cleanup]('.$this->cleanupUrl().')', true);

        return $message;
    }

    public function toTelegram(): array
    {
        return [
            'message' => "Coolify: Automatic update to {$this->version} skipped.\nFree disk space: {$this->availableGb} GB. Required: {$this->requiredGb} GB.\nFree up disk space (for example, run a Docker cleanup): {$this->cleanupUrl()}",
        ];
    }

    public function toPushover(): PushoverMessage
    {
        return new PushoverMessage(
            title: 'Automatic update skipped',
            level: 'warning',
            message: "Coolify did not update to {$this->version} because the server does not have enough free disk space.<br/><br/><b>Free:</b> {$this->availableGb} GB.<br/><b>Required:</b> {$this->requiredGb} GB.",
            buttons: [
                'Docker cleanup' => $this->cleanupUrl(),
            ],
        );
    }

    public function toSlack(): SlackMessage
    {
        $description = "Coolify did not update to {$this->version} because the server does not have enough free disk space.\n";
        $description .= "Free: {$this->availableGb} GB\n";
        $description .= "Required: {$this->requiredGb} GB\n\n";
        $description .= 'Free up disk space (for example, run a Docker cleanup): '.$this->cleanupUrl();

        return new SlackMessage(
            title: 'Automatic update skipped',
            description: $description,
            color: SlackMessage::warningColor()
        );
    }

    public function toWebhook(): array
    {
        return [
            'success' => false,
            'message' => 'Automatic update skipped, not enough free disk space',
            'event' => 'upgrade_skipped_low_disk_space',
            'version' => $this->version,
            'server_name' => $this->server->name,
            'server_uuid' => $this->server->uuid,
            'available_gb' => $this->availableGb,
            'required_gb' => $this->requiredGb,
            'url' => $this->cleanupUrl(),
        ];
    }
}
