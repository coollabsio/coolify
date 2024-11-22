<?php

namespace App\Notifications\TransactionalEmails;

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\Channels\TransactionalEmailChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvitationLink extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 5;

    public function via(): array
    {
        return [TransactionalEmailChannel::class];
    }

    public function __construct(public User $user) {}

    public function toMail(): MailMessage
    {
        $invitation = TeamInvitation::whereEmail($this->user->email)->first();
        $invitation_team = Team::find($invitation->team->id);

        $mail = new MailMessage;
        $mail->subject('Coolify: Invitation for '.$invitation_team->name);
        $mail->view('emails.invitation-link', [
            'team' => $invitation_team->name,
            'email' => $this->user->email,
            'invitation_link' => $invitation->link,
        ]);

        return $mail;
    }
}
