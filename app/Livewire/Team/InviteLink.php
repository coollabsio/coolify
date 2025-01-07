<?php

namespace App\Livewire\Team;

use App\Models\TeamInvitation;
use App\Models\User;
use Exception;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Component;
use Throwable;
use Visus\Cuid2\Cuid2;

class InviteLink extends Component
{
    public string $email;

    public string $role = 'member';

    protected $rules = [
        'email' => 'required|email',
        'role' => 'required|string',
    ];

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
            $this->validate();
            if (auth()->user()->role() === 'admin' && $this->role === 'owner') {
                throw new Exception('Admins cannot invite owners.');
            }
            $member_emails = currentTeam()->members()->get()->pluck('email');
            if ($member_emails->contains($this->email)) {
                return handleError(livewire: $this, customErrorMessage: "$this->email is already a member of ".currentTeam()->name.'.');
            }
            $cuid2 = new Cuid2(32);
            $link = url('/').config('constants.invitation.link.base_url').$cuid2;
            $user = User::whereEmail($this->email)->first();

            if (is_null($user)) {
                $password = Str::password();
                $user = User::query()->create([
                    'name' => str($this->email)->before('@'),
                    'email' => $this->email,
                    'password' => Hash::make($password),
                    'force_password_reset' => true,
                ]);
                $token = Crypt::encryptString("{$user->email}@@@$password");
                $link = route('auth.link', ['token' => $token]);
            }
            $invitation = TeamInvitation::whereEmail($this->email)->first();
            if (! is_null($invitation)) {
                $invitationValid = $invitation->isValid();
                if ($invitationValid) {
                    return handleError(livewire: $this, customErrorMessage: "Pending invitation already exists for $this->email.");
                }
                $invitation->delete();
            }

            $invitation = TeamInvitation::query()->firstOrCreate([
                'team_id' => currentTeam()->id,
                'uuid' => $cuid2,
                'email' => $this->email,
                'role' => $this->role,
                'link' => $link,
                'via' => $sendEmail ? 'email' : 'link',
            ]);
            if ($sendEmail) {
                $mailMessage = new MailMessage;
                $mailMessage->view('emails.invitation-link', [
                    'team' => currentTeam()->name,
                    'invitation_link' => $link,
                ]);
                $mailMessage->subject('You have been invited to '.currentTeam()->name.' on '.config('app.name').'.');
                send_user_an_email($mailMessage, $this->email);
                $this->dispatch('success', 'Invitation sent via email.');
                $this->dispatch('refreshInvitations');

                return null;
            }
            $this->dispatch('success', 'Invitation link generated.');
            $this->dispatch('refreshInvitations');
        } catch (Throwable $e) {
            $error_message = $e->getMessage();
            if ($e->getCode() === '23505') {
                $error_message = 'Invitation already sent.';
            }

            return handleError(error: $e, livewire: $this, customErrorMessage: $error_message);
        }

        return null;
    }
}
