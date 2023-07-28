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
    public function via()
    {
        return [TransactionalEmailChannel::class];
    }

    public function toMail(User $user): MailMessage
    {
        $invitation = TeamInvitation::whereEmail($user->email)->first();
        $invitation_team = Team::find($invitation->team->id);

        $mail = new MailMessage();
        $mail->subject('Invitation for ' . $invitation_team->name);
        $mail->view('emails.invitation-link', [
            'team' => $invitation_team->name,
            'email' => $user->email,
            'invitation_link' => $invitation->link,
        ]);
        return $mail;
    }
}