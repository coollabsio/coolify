<?php

namespace App\Livewire\Team;

use App\Actions\User\RevokeUserTeamTokens;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class Member extends Component
{
    use AuthorizesRequests;

    public User $member;

    public function makeAdmin()
    {
        try {
            $this->authorize('manageMembers', currentTeam());

            if (Role::from(auth()->user()->role())->lt(Role::ADMIN)
                || Role::from($this->getMemberRole())->gt(auth()->user()->role())) {
                throw new \Exception('You are not authorized to perform this action.');
            }
            $teamId = currentTeam()->id;
            $this->member->teams()->updateExistingPivot($teamId, ['role' => Role::ADMIN->value]);
            RevokeUserTeamTokens::forUserTeam($this->member, $teamId);
            $this->dispatch('reloadWindow');
        } catch (\Exception $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    public function makeOwner()
    {
        try {
            $this->authorize('manageMembers', currentTeam());

            if (Role::from(auth()->user()->role())->lt(Role::OWNER)
                || Role::from($this->getMemberRole())->gt(auth()->user()->role())) {
                throw new \Exception('You are not authorized to perform this action.');
            }
            $teamId = currentTeam()->id;
            $this->member->teams()->updateExistingPivot($teamId, ['role' => Role::OWNER->value]);
            RevokeUserTeamTokens::forUserTeam($this->member, $teamId);
            $this->dispatch('reloadWindow');
        } catch (\Exception $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    public function makeReadonly()
    {
        try {
            $this->authorize('manageMembers', currentTeam());

            if (Role::from(auth()->user()->role())->lt(Role::ADMIN)
                || Role::from($this->getMemberRole())->gt(auth()->user()->role())) {
                throw new \Exception('You are not authorized to perform this action.');
            }
            $teamId = currentTeam()->id;
            $this->member->teams()->updateExistingPivot($teamId, ['role' => Role::MEMBER->value]);
            RevokeUserTeamTokens::forUserTeam($this->member, $teamId);
            $this->dispatch('reloadWindow');
        } catch (\Exception $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    public function remove()
    {
        try {
            $this->authorize('manageMembers', currentTeam());

            if (Role::from(auth()->user()->role())->lt(Role::ADMIN)
                || Role::from($this->getMemberRole())->gt(auth()->user()->role())) {
                throw new \Exception('You are not authorized to perform this action.');
            }
            $teamId = currentTeam()->id;
            $this->member->teams()->detach(currentTeam());
            RevokeUserTeamTokens::forUserTeam($this->member, $teamId);
            // Clear cache for the removed user - both old and new key formats
            Cache::forget("team:{$this->member->id}");
            Cache::forget("user:{$this->member->id}:team:{$teamId}");
            $this->dispatch('reloadWindow');
        } catch (\Exception $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    private function getMemberRole()
    {
        return $this->member->teams()->where('teams.id', currentTeam()->id)->first()?->pivot?->role;
    }
}
