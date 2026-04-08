<?php

namespace App\Notifications;

use App\Notifications\Channels\EmailChannel;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * @phpstan-type MasterUpdateItem array{
 *     label: string,
 *     summary: string,
 *     url: string|null
 * }
 * @phpstan-type MasterUpdatePackage array{
 *     label: string,
 *     summary: string
 * }
 * @phpstan-type MasterUpdateServerPatchGroup array{
 *     label: string,
 *     url: string|null,
 *     packages: array<int, MasterUpdatePackage>
 * }
 */
class MasterUpdateReport extends CustomEmailNotification
{
    /**
     * @param array{
     *     coolify_upgrades: array<int, MasterUpdateItem>,
     *     proxy_upgrades: array<int, MasterUpdateItem>,
     *     server_patches: array<int, MasterUpdateServerPatchGroup>,
     *     container_image_updates: array<int, MasterUpdateItem>
     * } $sections
     */
    public function __construct(
        public array $sections,
        public int $totalUpdates
    ) {}

    public function via(object $notifiable): array
    {
        return [EmailChannel::class];
    }

    public function toMail($notifiable = null): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject("Coolify: Master update report ({$this->totalUpdates} new update".($this->totalUpdates === 1 ? '' : 's').')');
        $mail->view('emails.master-update-report', [
            'sections' => $this->sections,
            'totalUpdates' => $this->totalUpdates,
        ]);

        return $mail;
    }
}
