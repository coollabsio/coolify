<?php

namespace App\Livewire\Admin;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class Index extends Component
{
    public int $activeSubscribers;

    public int $inactiveSubscribers;

    public Collection $foundUsers;

    public string $search = '';

    public function mount()
    {
        if (! isCloud()) {
            return redirect()->route('dashboard');
        }

        if (auth()->user()->id !== 0) {
            return redirect()->route('dashboard');
        }
        $this->getSubscribers();
    }

    public function submitSearch()
    {
        if ($this->search !== '') {
            $this->foundUsers = User::where(function ($query) {
                $query->where('name', 'like', "%{$this->search}%")
                    ->orWhere('email', 'like', "%{$this->search}%");
            })->get();
        }
    }

    public function getSubscribers()
    {
        $this->inactiveSubscribers = User::whereDoesntHave('teams', function ($query) {
            $query->whereRelation('subscription', 'stripe_subscription_id', '!=', null);
        })->count();
        $this->activeSubscribers = User::whereHas('teams', function ($query) {
            $query->whereRelation('subscription', 'stripe_subscription_id', '!=', null);
        })->count();
    }

    public function switchUser(int $user_id)
    {
        if (auth()->user()->id !== 0) {
            return redirect()->route('dashboard');
        }
        $user = User::find($user_id);
        $team_to_switch_to = $user->teams->first();
        Cache::forget("team:{$user->id}");
        auth()->login($user);
        refreshSession($team_to_switch_to);

        return redirect(request()->header('Referer'));
    }

    public function render()
    {
        return view('livewire.admin.index');
    }
}
