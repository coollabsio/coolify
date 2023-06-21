<?php

namespace App\Notifications\TransactionalEmails;

use App\Notifications\Channels\EmailChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TestEmail extends Notification implements ShouldQueue
{
    use Queueable;
    public function via(): array
    {
        return [EmailChannel::class];
    }
    public function toMail(): MailMessage
    {
        $mail = new MailMessage();
        $mail->subject('Test Notification');
        $mail->view('emails.test');
        return $mail;
    }
}
