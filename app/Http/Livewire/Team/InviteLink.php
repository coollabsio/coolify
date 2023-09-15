<?php

namespace App\Http\Livewire\Team;

use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Artisan;
use Livewire\Component;
use Visus\Cuid2\Cuid2;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class InviteLink extends Component
{
    public string $email;
    public string $role = 'member';

    public function mount()
    {
        $this->email = isDev() ? 'test3@example.com' : '';
    }

    public function viaEmail()
    {
        $this->generate_invite_link(sendEmail: true);
    }

    public function viaLink()
    {
        $this->generate_invite_link(sendEmail: false);
    }
    private function generate_invite_link(bool $sendEmail = false)
    {
        try {
            $member_emails = currentTeam()->members()->get()->pluck('email');
            if ($member_emails->contains($this->email)) {
                return general_error_handler(that: $this, customErrorMessage: "$this->email is already a member of " . currentTeam()->name . ".");
            }
            $uuid = new Cuid2(32);
            $link = url('/') . config('constants.invitation.link.base_url') . $uuid;
            $user = User::whereEmail($this->email)->first();

            if (is_null($user)) {
                $password = Str::password();
                $user = User::create([
                    'name' => Str::of($this->email)->before('@'),
                    'email' => $this->email,
                    'password' => Hash::make($password),
                    'force_password_reset' => true,
                ]);
                $token = Crypt::encryptString("{$user->email}@@@$password");
                $link = route('auth.link', ['token' => $token]);
            }
            $invitation = TeamInvitation::whereEmail($this->email)->first();
            if (!is_null($invitation)) {
                $invitationValid = $invitation->isValid();
                if ($invitationValid) {
                    return general_error_handler(that: $this, customErrorMessage: "Pending invitation already exists for $this->email.");
                } else {
                    $invitation->delete();
                }
            }

            $invitation = TeamInvitation::firstOrCreate([
                'team_id' => currentTeam()->id,
                'uuid' => $uuid,
                'email' => $this->email,
                'role' => $this->role,
                'link' => $link,
                'via' => $sendEmail ? 'email' : 'link',
            ]);
            if ($sendEmail) {
                $mail = new MailMessage();
                $mail->view('emails.invitation-link', [
                    'team' => currentTeam()->name,
                    'invitation_link' => $link,
                ]);
                $mail->subject('You have been invited to ' . currentTeam()->name . ' on ' . config('app.name') . '.');
                send_user_an_email($mail, $this->email);
                $this->emit('success', 'Invitation sent via email successfully.');
                $this->emit('refreshInvitations');
                return;
            } else {
                $this->emit('success', 'Invitation link generated.');
                $this->emit('refreshInvitations');
            }
        } catch (\Throwable $e) {
            $error_message = $e->getMessage();
            if ($e->getCode() === '23505') {
                $error_message = 'Invitation already sent.';
            }
            return general_error_handler(err: $e, that: $this, customErrorMessage: $error_message);
        }
    }
}
