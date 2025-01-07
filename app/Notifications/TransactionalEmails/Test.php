<?php

namespace App\Notifications\TransactionalEmails;

use App\Notifications\Channels\EmailChannel;
use App\Notifications\CustomEmailNotification;
use Illuminate\Notifications\Messages\MailMessage;

class Test extends CustomEmailNotification
{
    public function __construct(public string $emails)
    {
        $this->onQueue('high');
    }

    public function via(): array
    {
        return [EmailChannel::class];
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject('Coolify: Test Email');
        $mail->view('emails.test');

        return $mail;
    }
}
