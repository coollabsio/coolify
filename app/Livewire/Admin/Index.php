<?php

namespace App\Livewire\Admin;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class Index extends Component
{
    public $active_subscribers = [];

    public $inactive_subscribers = [];

    public $search = '';

    public function submitSearch()
    {
        if ($this->search !== '') {
            $this->inactive_subscribers = User::whereDoesntHave('teams', function ($query) {
                $query->whereRelation('subscription', 'stripe_subscription_id', '!=', null);
            })->where(function ($query) {
                $query->where('name', 'like', "%{$this->search}%")
                    ->orWhere('email', 'like', "%{$this->search}%");
            })->get()->filter(function ($user) {
                return $user->id !== 0;
            });
            $this->active_subscribers = User::whereHas('teams', function ($query) {
                $query->whereRelation('subscription', 'stripe_subscription_id', '!=', null);
            })->where(function ($query) {
                $query->where('name', 'like', "%{$this->search}%")
                    ->orWhere('email', 'like', "%{$this->search}%");
            })->get()->filter(function ($user) {
                return $user->id !== 0;
            });
        } else {
            $this->getSubscribers();
        }
    }

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

    public function getSubscribers()
    {
        $this->inactive_subscribers = User::whereDoesntHave('teams', function ($query) {
            $query->whereRelation('subscription', 'stripe_subscription_id', '!=', null);
        })->get()->filter(function ($user) {
            return $user->id !== 0;
        });
        $this->active_subscribers = User::whereHas('teams', function ($query) {
            $query->whereRelation('subscription', 'stripe_subscription_id', '!=', null);
        })->get()->filter(function ($user) {
            return $user->id !== 0;
        });
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
