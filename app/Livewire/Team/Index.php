<?php

namespace App\Livewire\Team;

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Services\TeamAvatarStorageService;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Livewire\WithFileUploads;

class Index extends Component
{
    use AuthorizesRequests;
    use WithFileUploads;

    public $invitations = [];

    public Team $team;

    // Explicit properties
    public string $name;

    public ?string $description = null;

    public bool $is_mcp_server_enabled = true;

    public bool $is_build_server_fallback_enabled = true;

    public bool $is_two_factor_required = false;

    public $avatar;

    protected function rules(): array
    {
        return [
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
            'is_mcp_server_enabled' => 'boolean',
            'is_build_server_fallback_enabled' => 'boolean',
            'is_two_factor_required' => 'boolean',
        ];
    }

    protected function messages(): array
    {
        return array_merge(
            ValidationPatterns::combinedMessages(),
            [
                'name.required' => 'The Name field is required.',
            ]
        );
    }

    protected $validationAttributes = [
        'name' => 'name',
        'description' => 'description',
    ];

    /**
     * Sync data between component properties and model
     *
     * @param  bool  $toModel  If true, sync FROM properties TO model. If false, sync FROM model TO properties.
     */
    private function syncData(bool $toModel = false): void
    {
        if ($toModel) {
            // Sync TO model (before save)
            $this->team->name = $this->name;
            $this->team->description = $this->description;
            $this->team->is_mcp_server_enabled = $this->is_mcp_server_enabled;
            $this->team->is_build_server_fallback_enabled = $this->is_build_server_fallback_enabled;
            $this->team->is_two_factor_required = $this->is_two_factor_required;
        } else {
            // Sync FROM model (on load/refresh)
            $this->name = $this->team->name;
            $this->description = $this->team->description;
            // Null can appear after Team::create() when the DB default is not
            // hydrated onto the in-memory model stored in session.
            $this->is_mcp_server_enabled = (bool) ($this->team->is_mcp_server_enabled ?? true);
            $this->is_build_server_fallback_enabled = (bool) ($this->team->is_build_server_fallback_enabled ?? true);
            $this->is_two_factor_required = (bool) $this->team->is_two_factor_required;
        }
    }

    public function mount()
    {
        $this->team = currentTeam();
        $this->syncData(false);

        if (auth()->user()->isAdminFromSession()) {
            $this->invitations = TeamInvitation::whereTeamId(currentTeam()->id)->get();
        }
    }

    public function uploadAvatar(TeamAvatarStorageService $avatarStorage): bool
    {
        try {
            $this->authorize('update', $this->team);
            $this->validate([
                'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=6000,max_height=6000'],
            ]);
            $avatarStorage->storeTeam($this->team, $this->avatar);
            $this->reset('avatar');
            refreshSession();
            $this->dispatch('team-avatar-updated');
            $this->dispatch('success', 'Team avatar updated.');

            return true;
        } catch (\Throwable $e) {
            handleError($e, $this);

            return false;
        }
    }

    public function removeAvatar(TeamAvatarStorageService $avatarStorage): void
    {
        try {
            $this->authorize('update', $this->team);
            $avatarStorage->deleteTeam($this->team);
            refreshSession();
            $this->dispatch('team-avatar-updated');
            $this->dispatch('success', 'Team avatar removed.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.team.index');
    }

    public function submit()
    {
        $this->validate();
        try {
            $this->authorize('update', $this->team);
            if ($this->is_two_factor_required && ! $this->team->is_two_factor_required && blank(auth()->user()->two_factor_confirmed_at)) {
                $this->is_two_factor_required = false;
                $this->dispatch('error', 'Enable two-factor authentication on your profile before you require it for the team.');

                return;
            }
            $this->syncData(true);
            $this->team->save();
            refreshSession();
            $this->dispatch('success', 'Team updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
