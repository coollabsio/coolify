<?php

namespace App\Notifications\Server;

use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

class OpenstackDeletionFailed extends CustomEmailNotification
{
    public function __construct(public string $openstackServerId, public int $teamId, public string $errorMessage)
    {
        $this->onQueue('high');
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('openstack_deletion_failure');
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject("Coolify: [ACTION REQUIRED] Failed to delete OpenStack server {$this->openstackServerId}");
        $mail->view('emails.openstack-deletion-failed', [
            'openstackServerId' => $this->openstackServerId,
            'errorMessage' => $this->errorMessage,
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        return new DiscordMessage(
            title: ':cross_mark: Coolify: [ACTION REQUIRED] Failed to delete OpenStack server',
            description: "Failed to delete OpenStack server {$this->openstackServerId}.\n\n**Error:** {$this->errorMessage}\n\nThe server has been removed from Coolify, but may still exist in your OpenStack project. Please check your OpenStack dashboard and manually delete the instance (and any floating IP) if needed.",
            color: DiscordMessage::errorColor(),
        );
    }

    public function toTelegram(): array
    {
        return [
            'message' => "Coolify: [ACTION REQUIRED] Failed to delete OpenStack server {$this->openstackServerId}.\n\nError: {$this->errorMessage}\n\nThe server has been removed from Coolify, but may still exist in your OpenStack project. Please check your OpenStack dashboard and manually delete the instance (and any floating IP) if needed.",
        ];
    }

    public function toPushover(): PushoverMessage
    {
        return new PushoverMessage(
            title: 'OpenStack Server Deletion Failed',
            level: 'error',
            message: "[ACTION REQUIRED] Failed to delete OpenStack server {$this->openstackServerId}.\n\nError: {$this->errorMessage}\n\nThe server has been removed from Coolify, but may still exist in your OpenStack project. Please check and manually delete if needed.",
        );
    }

    public function toSlack(): SlackMessage
    {
        return new SlackMessage(
            title: 'Coolify: [ACTION REQUIRED] OpenStack Server Deletion Failed',
            description: "Failed to delete OpenStack server {$this->openstackServerId}.\n\nError: {$this->errorMessage}\n\nThe server has been removed from Coolify, but may still exist in your OpenStack project. Please check your OpenStack dashboard and manually delete the instance (and any floating IP) if needed.",
            color: SlackMessage::errorColor()
        );
    }
}
