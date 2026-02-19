<?php

namespace App\Livewire\Project;

use App\Enums\Role;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class Members extends Component
{
    use AuthorizesRequests;

    public Project $project;

    public string $email = '';

    public string $role = 'member';

    protected $rules = [
        'email' => 'required|email',
        'role' => 'required|string|in:member,admin',
    ];

    public function mount(string $project_uuid)
    {
        try {
            $this->project = Project::where('team_id', currentTeam()->id)->where('uuid', $project_uuid)->firstOrFail();
            $this->authorize('manageMembers', $this->project);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function updatedEmail()
    {
        $this->email = strtolower($this->email);
    }

    public function addMember()
    {
        try {
            $this->authorize('manageMembers', $this->project);
            $this->validate();

            // Prevent privilege escalation: project members cannot invite admins
            if (auth()->user()->isMember() && $this->role === 'admin') {
                throw new \Exception('Members cannot invite admins.');
            }

            $this->email = strtolower($this->email);

            // Check if already a team member (team members already have access)
            $teamMember = currentTeam()->members()->where('email', $this->email)->first();
            if ($teamMember) {
                return handleError(livewire: $this, customErrorMessage: "$this->email is already a member of the team and has full access.");
            }

            // Check if already a project member
            $existingMember = $this->project->members()->whereHas('user', function ($query) {
                $query->where('email', $this->email);
            })->first();
            if ($existingMember) {
                return handleError(livewire: $this, customErrorMessage: "$this->email is already a member of this project.");
            }

            // Find or create user
            $user = User::whereEmail($this->email)->first();
            $isNewUser = false;

            if (is_null($user)) {
                $password = Str::password();
                $user = User::create([
                    'name' => str($this->email)->before('@'),
                    'email' => $this->email,
                    'password' => Hash::make($password),
                    'force_password_reset' => true,
                ]);
                $isNewUser = true;
            }

            // Add user to project
            $this->project->members()->attach($user, ['role' => $this->role]);

            // Send invitation email if transactional emails are enabled
            if (is_transactional_emails_enabled()) {
                $this->sendInvitationEmail($user, $isNewUser ? $password : null);
            }

            $this->dispatch('success', 'Member added successfully.');
            $this->reset(['email', 'role']);
            $this->dispatch('refreshMembers');
        } catch (\Throwable $e) {
            return handleError(error: $e, livewire: $this);
        }
    }

    private function sendInvitationEmail(User $user, ?string $password = null)
    {
        $mail = new MailMessage;
        $projectName = $this->project->name;
        $teamName = currentTeam()->name;

        $invitationData = [
            'project' => $projectName,
            'team' => $teamName,
            'role' => $this->role,
        ];

        $invitationData['project_uuid'] = $this->project->uuid;

        if ($password) {
            // New user - send login credentials
            $token = Crypt::encryptString("{$user->email}@@@$password");
            $loginLink = route('auth.link', ['token' => $token]);
            $invitationData['login_link'] = $loginLink;

            $mail->view('emails.project-invitation-new-user', $invitationData);
            $mail->subject("You have been invited to {$projectName} on ".config('app.name'));
        } else {
            // Existing user - just notify
            $invitationData['app_url'] = base_url();
            $mail->view('emails.project-invitation-existing-user', $invitationData);
            $mail->subject("You have been added to {$projectName} on ".config('app.name'));
        }

        send_user_an_email($mail, $user->email);
    }

    public function removeMember(int $user_id)
    {
        try {
            $this->authorize('manageMembers', $this->project);

            $user = User::findOrFail($user_id);

            // Prevent removing yourself
            if ($user_id === auth()->id()) {
                throw new \Exception('You cannot remove yourself.');
            }

            $this->project->members()->detach($user_id);

            // Clean up user if they have no teams and no other project memberships
            $user->deleteIfNotVerifiedAndForcePasswordReset();

            $this->dispatch('success', 'Member removed successfully.');
            $this->dispatch('refreshMembers');
        } catch (\Throwable $e) {
            return handleError(error: $e, livewire: $this);
        }
    }

    public function changeRole(int $user_id, string $newRole)
    {
        try {
            $this->authorize('manageMembers', $this->project);

            // Prevent privilege escalation
            if (auth()->user()->isMember() && $newRole === 'admin') {
                throw new \Exception('Members cannot promote to admin.');
            }

            $user = User::findOrFail($user_id);

            // Prevent changing your own role
            if ($user_id === auth()->id()) {
                throw new \Exception('You cannot change your own role.');
            }

            $this->project->members()->updateExistingPivot($user_id, ['role' => $newRole]);

            $this->dispatch('success', 'Role updated successfully.');
            $this->dispatch('refreshMembers');
        } catch (\Throwable $e) {
            return handleError(error: $e, livewire: $this);
        }
    }

    public function render()
    {
        return view('livewire.project.members');
    }
}
