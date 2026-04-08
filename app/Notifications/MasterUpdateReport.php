<?php

namespace App\Notifications;

use App\Notifications\Channels\EmailChannel;
use Illuminate\Notifications\Messages\MailMessage;

class MasterUpdateReport extends CustomEmailNotification
{
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
