<?php

namespace App\Notifications\TransactionalEmails;

use App\Models\User;
use App\Notifications\Channels\TransactionalEmailChannel;
use App\Notifications\CustomEmailNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;

class EmailChangeVerification extends CustomEmailNotification
{
    public function via(): array
    {
        return [TransactionalEmailChannel::class];
    }

    public function __construct(
        public User $user,
        public string $verificationCode,
        public string $newEmail,
        public Carbon $expiresAt,
        public bool $isTransactionalEmail = true
    ) {
        $this->onQueue('high');
    }

    public function toMail(): MailMessage
    {
        // Use the configured expiry minutes value
        $expiryMinutes = config('constants.email_change.verification_code_expiry_minutes', 10);

        $mail = new MailMessage;
        $mail->subject('Coolify: Verify Your New Email Address');
        $mail->view('emails.email-change-verification', [
            'newEmail' => $this->newEmail,
            'verificationCode' => $this->verificationCode,
            'expiryMinutes' => $expiryMinutes,
        ]);

        return $mail;
    }
}
