<?php

namespace App\Livewire\Team;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class Member extends Component
{
    public User $member;

    public function makeAdmin()
    {
        try {
            if (! auth()->user()->isAdmin()) {
                throw new \Exception('You are not authorized to perform this action.');
            }
            $this->member->teams()->updateExistingPivot(currentTeam()->id, ['role' => 'admin']);
            $this->dispatch('reloadWindow');
        } catch (\Exception $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    public function makeOwner()
    {
        try {
            if (! auth()->user()->isOwner()) {
                throw new \Exception('You are not authorized to perform this action.');
            }
            $this->member->teams()->updateExistingPivot(currentTeam()->id, ['role' => 'owner']);
            $this->dispatch('reloadWindow');
        } catch (\Exception $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    public function makeReadonly()
    {
        try {
            if (! auth()->user()->isAdmin()) {
                throw new \Exception('You are not authorized to perform this action.');
            }
            $this->member->teams()->updateExistingPivot(currentTeam()->id, ['role' => 'member']);
            $this->dispatch('reloadWindow');
        } catch (\Exception $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    public function remove()
    {
        try {
            if (! auth()->user()->isAdmin()) {
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
}
