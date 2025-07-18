<?php

namespace App\Livewire\Team;

use App\Models\InstanceSettings;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Component;

class AdminView extends Component
{
    public $users;

    public ?string $search = '';

    public bool $lots_of_users = false;

    private $number_of_users_to_show = 20;

    public function mount()
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }
        $this->getUsers();
    }

    public function submitSearch()
    {
        if ($this->search !== '') {
            $this->users = User::where(function ($query) {
                $query->where('name', 'like', "%{$this->search}%")
                    ->orWhere('email', 'like', "%{$this->search}%");
            })->get()->filter(function ($user) {
                return $user->id !== auth()->id();
            });
        } else {
            $this->getUsers();
        }
    }

    public function getUsers()
    {
        $users = User::where('id', '!=', auth()->id())->get();
        if ($users->count() > $this->number_of_users_to_show) {
            $this->lots_of_users = true;
            $this->users = $users->take($this->number_of_users_to_show);
        } else {
            $this->lots_of_users = false;
            $this->users = $users;
        }
    }

    public function delete($id, $password)
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }

        if (! data_get(InstanceSettings::get(), 'disable_two_step_confirmation')) {
            if (! Hash::check($password, Auth::user()->password)) {
                $this->addError('password', 'The provided password is incorrect.');

                return;
            }
        }

        if (! auth()->user()->isInstanceAdmin()) {
            return $this->dispatch('error', 'You are not authorized to delete users');
        }

        $user = User::find($id);
        if (! $user) {
            return $this->dispatch('error', 'User not found');
        }

        try {
            $user->delete();
            $this->getUsers();
        } catch (\Exception $e) {
            return $this->dispatch('error', $e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.team.admin-view');
    }
}
