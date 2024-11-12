<?php

namespace App\Notifications\Server;

use App\Models\Server;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class HighDiskUsage extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public function __construct(public Server $server, public int $disk_usage, public int $server_disk_usage_notification_threshold)
    {
    }

    public function via(object $notifiable): array
    {
        return setNotificationChannels($notifiable, 'server_disk_usage');
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject("Coolify: Server ({$this->server->name}) high disk usage detected!");
        $mail->view('emails.high-disk-usage', [
            'name' => $this->server->name,
            'disk_usage' => $this->disk_usage,
            'threshold' => $this->server_disk_usage_notification_threshold,
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        $message = new DiscordMessage(
            title: ':cross_mark: High disk usage detected',
            description: "Server '{$this->server->name}' high disk usage detected!",
            color: DiscordMessage::errorColor(),
            isCritical: true,
        );

        $message->addField('Disk usage', "{$this->disk_usage}%", true);
        $message->addField('Threshold', "{$this->server_disk_usage_notification_threshold}%", true);
        $message->addField('What to do?', '[Link](https://coolify.io/docs/knowledge-base/server/automated-cleanup)', true);
        $message->addField('Change Settings', '[Threshold](' . base_url() . '/server/' . $this->server->uuid . '#advanced) | [Notification](' . base_url() . '/notifications/discord)');

        return $message;
    }

    public function toTelegram(): array
    {
        return [
            'message' => "Coolify: Server '{$this->server->name}' high disk usage detected!\nDisk usage: {$this->disk_usage}%. Threshold: {$this->server_disk_usage_notification_threshold}%.\nPlease cleanup your disk to prevent data-loss.\nHere are some tips: https://coolify.io/docs/knowledge-base/server/automated-cleanup.",
        ];
    }

    public function toSlack(): SlackMessage
    {
        $description = "Server '{$this->server->name}' high disk usage detected!\n";
        $description .= "Disk usage: {$this->disk_usage}%\n";
        $description .= "Threshold: {$this->server_disk_usage_notification_threshold}%\n\n";
        $description .= "Please cleanup your disk to prevent data-loss.\n";
        $description .= "Tips for cleanup: https://coolify.io/docs/knowledge-base/server/automated-cleanup\n";
        $description .= "Change settings:\n";
        $description .= "- Threshold: " . base_url() . "/server/" . $this->server->uuid . "#advanced\n";
        $description .= "- Notifications: " . base_url() . "/notifications/discord";

        return new SlackMessage(
            title: 'High disk usage detected',
            description: $description,
            color: SlackMessage::errorColor()
        );
    }
}
