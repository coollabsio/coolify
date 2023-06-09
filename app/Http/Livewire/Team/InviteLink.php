<?php

namespace App\Http\Livewire\Team;

use App\Models\TeamInvitation;
use App\Models\User;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class InviteLink extends Component
{
    public string $email;
    public function mount()
    {
        $this->email = config('app.env') === 'local' ? 'test@example.com' : '';
    }
    public function inviteByLink()
    {
        $uuid = new Cuid2(32);
        $link = url('/') . '/api/invitation/' . $uuid;
        try {
            $user_exists = User::whereEmail($this->email)->exists();
            if (!$user_exists) {
                return general_error_handler(that: $this, customErrorMessage: "$this->email must be registered first (or activate transactional emails to invite via email).");
            }
            $invitation = TeamInvitation::where('email', $this->email);
            if ($invitation->exists()) {
                $created_at = $invitation->first()->created_at;
                $diff = $created_at->diffInMinutes(now());
                if ($diff < 11) {
                    return general_error_handler(that: $this, customErrorMessage: "Invitation already sent and active for $this->email.");
                } else {
                    $invitation->delete();
                }
            }
            $invitation = TeamInvitation::firstOrCreate([
                'team_id' => session('currentTeam')->id,
                'email' => $this->email,
                'role' => 'readonly',
                'link' => $link,
            ]);
            $this->emit('reloadWindow');
        } catch (\Throwable $e) {
            $error_message = $e->getMessage();
            if ($e->getCode() === '23505') {
                $error_message = 'Invitation already sent.';
            }
            return general_error_handler(err: $e, that: $this, customErrorMessage: $error_message);
        }
    }
}
