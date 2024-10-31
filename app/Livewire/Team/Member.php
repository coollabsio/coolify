<?php

namespace App\Livewire\Team;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class Member extends Component
{
    public User $member;

    public function makeAdmin()
    {
        try {
            if (Role::from(auth()->user()->role())->lt(Role::ADMIN)
                || Role::from($this->getMemberRole())->gt(auth()->user()->role())) {
                throw new \Exception('You are not authorized to perform this action.');
            }
            $this->member->teams()->updateExistingPivot(currentTeam()->id, ['role' => Role::ADMIN->value]);
            $this->dispatch('reloadWindow');
        } catch (\Exception $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    public function makeOwner()
    {
        try {
            if (Role::from(auth()->user()->role())->lt(Role::OWNER)
                || Role::from($this->getMemberRole())->gt(auth()->user()->role())) {
                throw new \Exception('You are not authorized to perform this action.');
            }
            $this->member->teams()->updateExistingPivot(currentTeam()->id, ['role' => Role::OWNER->value]);
            $this->dispatch('reloadWindow');
        } catch (\Exception $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    public function makeReadonly()
    {
        try {
            if (Role::from(auth()->user()->role())->lt(Role::ADMIN)
                || Role::from($this->getMemberRole())->gt(auth()->user()->role())) {
                throw new \Exception('You are not authorized to perform this action.');
            }
            $this->member->teams()->updateExistingPivot(currentTeam()->id, ['role' => Role::MEMBER->value]);
            $this->dispatch('reloadWindow');
        } catch (\Exception $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    public function remove()
    {
        try {
            if (Role::from(auth()->user()->role())->lt(Role::ADMIN)
                || Role::from($this->getMemberRole())->gt(auth()->user()->role())) {
                throw new \Exception('You are not authorized to perform this action.');
            }
            $this->member->teams()->detach(currentTeam());
            Cache::forget("team:{$this->member->id}");
            Cache::remember('team:'.$this->member->id, 3600, function () {
                return $this->member->teams()->first();
            });
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
