<?php

namespace App\Notifications\TransactionalEmails;

use App\Notifications\Channels\EmailChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class Test extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 5;

    public function __construct(public string $emails) {}

    public function via(): array
    {
        return [EmailChannel::class];
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage();
        $mail->subject('Coolify: Test Email');
        $mail->view('emails.test');

        return $mail;
    }
}
