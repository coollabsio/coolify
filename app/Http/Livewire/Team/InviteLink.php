<?php

namespace App\Http\Livewire\Team;

use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\TransactionalEmails\InvitationLinkEmail;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class InviteLink extends Component
{
    public string $email;
    public string $role = 'member';
    public function mount()
    {
        $this->email = config('app.env') === 'local' ? 'test3@example.com' : '';
    }
    public function viaEmail()
    {
        $this->generate_invite_link(isEmail: true);
    }
    private function generate_invite_link(bool $isEmail = false)
    {
        try {
            $uuid = new Cuid2(32);
            $link = url('/') . config('constants.invitation.link.base_url') . $uuid;

            $user = User::whereEmail($this->email);

            if (!$user->exists()) {
                return general_error_handler(that: $this, customErrorMessage: "$this->email must be registered first (or activate transactional emails to invite via email).");
            }

            $member_emails = session('currentTeam')->members()->get()->pluck('email');
            if ($member_emails->contains($this->email)) {
                return general_error_handler(that: $this, customErrorMessage: "$this->email is already a member of " . session('currentTeam')->name . ".");
            }

            $invitation = TeamInvitation::whereEmail($this->email);

            if ($invitation->exists()) {
                $created_at = $invitation->first()->created_at;
                $diff = $created_at->diffInMinutes(now());
                if ($diff <= config('constants.invitation.link.expiration')) {
                    return general_error_handler(that: $this, customErrorMessage: "Invitation already sent to $this->email and waiting for action.");
                } else {
                    $invitation->delete();
                }
            }

            TeamInvitation::firstOrCreate([
                'team_id' => session('currentTeam')->id,
                'uuid' => $uuid,
                'email' => $this->email,
                'role' => $this->role,
                'link' => $link,
                'via' => $isEmail ? 'email' : 'link',
            ]);
            if ($isEmail) {
                $user->first()->notify(new InvitationLinkEmail());
                $this->emit('success', 'Invitation sent via email successfully.');
            } else {
                $this->emit('success', 'Invitation link generated.');
            }
            $this->emit('refreshInvitations');
        } catch (\Throwable $e) {
            $error_message = $e->getMessage();
            if ($e->getCode() === '23505') {
                $error_message = 'Invitation already sent.';
            }
            return general_error_handler(err: $e, that: $this, customErrorMessage: $error_message);
        }
    }
    public function viaLink()
    {
        $this->generate_invite_link();
    }
}
