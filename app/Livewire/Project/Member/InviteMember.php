<?php

namespace App\Livewire\Project\Member;

use App\Enums\ProjectMemberRole;
use App\Models\Project;
use App\Models\ProjectInvitation;
use App\Models\ProjectMember;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class InviteMember extends Component
{
    use AuthorizesRequests;

    public Project $project;

    public string $email = '';

    public string $role = 'viewer';

    protected $rules = [
        'email' => 'required|email',
        'role' => 'required|string|in:viewer,deployer,manager',
    ];

    public function mount(Project $project): void
    {
        $this->project = $project;
        $this->email = isDev() ? 'test3@example.com' : '';
    }

    public function inviteViaLink(): void
    {
        $this->inviteMember(sendEmail: false);
    }

    public function inviteViaEmail(): void
    {
        $this->inviteMember(sendEmail: true);
    }

    private function inviteMember(bool $sendEmail = false): void
    {
        try {
            // Check authorization
            $user = auth()->user();
            if (! $user->isAdmin() && ! $user->isOwner()) {
                $membership = $this->project->getProjectMember($user);
                if (! $membership?->canManage()) {
                    throw new \Exception('You are not authorized to invite members to this project.');
                }
            }

            $this->validate();

            $this->email = strtolower($this->email);

            // Check if already a project member
            $existingMember = ProjectMember::where('project_id', $this->project->id)
                ->whereHas('user', fn ($q) => $q->where('email', $this->email))
                ->first();

            if ($existingMember) {
                return handleError(livewire: $this, customErrorMessage: "$this->email is already a member of this project.");
            }

            // Check if already a team member (they don't need project-specific access)
            $teamMembers = currentTeam()->members()->get()->pluck('email');
            if ($teamMembers->contains($this->email)) {
                return handleError(livewire: $this, customErrorMessage: "$this->email is already a team member and has full access. Project-specific membership is for users outside the team.");
            }

            // Check for existing pending invitation
            $existingInvitation = ProjectInvitation::where('project_id', $this->project->id)
                ->where('email', $this->email)
                ->first();

            if ($existingInvitation) {
                if ($existingInvitation->isValid()) {
                    return handleError(livewire: $this, customErrorMessage: "Pending invitation already exists for $this->email.");
                }
                $existingInvitation->delete();
            }

            $uuid = new Cuid2(32);
            $link = url('/') . '/project-invitation/' . $uuid;

            // Create or find user
            $invitedUser = User::where('email', $this->email)->first();
            if (is_null($invitedUser)) {
                $password = Str::password();
                $invitedUser = User::create([
                    'name' => str($this->email)->before('@'),
                    'email' => $this->email,
                    'password' => Hash::make($password),
                    'force_password_reset' => true,
                ]);
            }

            // Create the invitation
            $invitation = ProjectInvitation::create([
                'project_id' => $this->project->id,
                'team_id' => currentTeam()->id,
                'uuid' => $uuid,
                'email' => $this->email,
                'role' => $this->role,
                'link' => $link,
                'via' => $sendEmail ? 'email' : 'link',
                'invited_by' => auth()->id(),
            ]);

            if ($sendEmail) {
                $mail = new MailMessage;
                $mail->view('emails.project-invitation-link', [
                    'team' => currentTeam()->name,
                    'project' => $this->project->name,
                    'role' => ProjectMemberRole::from($this->role)->label(),
                    'invitation_link' => $link,
                ]);
                $mail->subject('You have been invited to project "' . $this->project->name . '" on ' . config('app.name') . '.');
                send_user_an_email($mail, $this->email);
                $this->dispatch('success', 'Invitation sent via email.');
            } else {
                $this->dispatch('success', 'Invitation link generated.');
            }

            $this->dispatch('refreshProjectMembers');
        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage();
            if ($e->getCode() === '23505') {
                $errorMessage = 'Invitation already sent.';
            }

            return handleError(error: $e, livewire: $this, customErrorMessage: $errorMessage);
        }
    }

    public function render()
    {
        return view('livewire.project.member.invite-member');
    }
}
