<?php

namespace App\Actions\Team;

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class InviteToTeam
{
    /**
     * Create (or refresh) a pending team invitation and return the invitation and its accept link.
     *
     * Mirrors the logic in App\Livewire\Team\InviteLink so the Railway members page and the
     * classic team page share the same invitation semantics.
     *
     * @return array{invitation: TeamInvitation, link: string}
     *
     * @throws \Exception when the inviter would escalate privileges, the target is already a
     *                    member, or a valid pending invitation already exists
     */
    public function handle(Team $team, User $inviter, string $email, string $role, bool $sendEmail = false): array
    {
        // Prevent privilege escalation: users cannot invite someone with higher privileges than themselves.
        $inviterRole = $inviter->roleInTeam($team->id);
        if (is_null($inviterRole) || ($inviterRole === 'member' && in_array($role, ['admin', 'owner']))) {
            throw new \Exception('Members cannot invite admins or owners.');
        }
        if ($inviterRole === 'admin' && $role === 'owner') {
            throw new \Exception('Admins cannot invite owners.');
        }

        $email = strtolower($email);

        if ($team->members()->get()->pluck('email')->contains($email)) {
            throw new \Exception("{$email} is already a member of {$team->name}.");
        }

        $uuid = new_public_id(32);
        $link = $this->invitationUrl('team.invitation.show', ['uuid' => $uuid]);
        $user = User::whereEmail($email)->first();

        // Brand-new users get a one-time magic link that also sets their password.
        if (is_null($user)) {
            $password = Str::password();
            $user = User::create([
                'name' => str($email)->before('@'),
                'email' => $email,
                'password' => Hash::make($password),
                'force_password_reset' => true,
            ]);
            $token = Crypt::encryptString("{$user->email}@@@{$uuid}@@@{$password}");
            $link = $this->invitationUrl('auth.link', ['token' => $token]);
        }

        $existing = TeamInvitation::whereEmail($email)->first();
        if (! is_null($existing)) {
            if ($existing->isValid()) {
                throw new \Exception("Pending invitation already exists for {$email}.");
            }
            $existing->delete();
        }

        $invitation = TeamInvitation::firstOrCreate([
            'team_id' => $team->id,
            'uuid' => $uuid,
            'email' => $email,
            'role' => $role,
            'link' => $link,
            'via' => $sendEmail ? 'email' : 'link',
        ]);

        if ($sendEmail) {
            $mail = new MailMessage;
            $mail->view('emails.invitation-link', [
                'team' => $team->name,
                'invitation_link' => $link,
            ]);
            $mail->subject('You have been invited to '.$team->name.' on '.config('app.name').'.');
            send_user_an_email($mail, $email);
        }

        return ['invitation' => $invitation, 'link' => $link];
    }

    private function invitationUrl(string $routeName, array $parameters): string
    {
        $fqdn = instanceSettings()->fqdn;
        if (filled($fqdn)) {
            return rtrim($fqdn, '/').route($routeName, $parameters, false);
        }

        return route($routeName, $parameters);
    }
}
