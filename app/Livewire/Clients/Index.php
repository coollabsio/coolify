<?php

namespace App\Livewire\Clients;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Component;

class Index extends Component
{
    public Collection $clients;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public function mount(): void
    {
        if (! auth()->user()?->isInstanceAdmin()) {
            abort(403);
        }

        $this->refreshClients();
    }

    public function render()
    {
        return view('livewire.clients.index');
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'password' => ['required', 'string', Password::defaults()],
        ];
    }

    public function create(): void
    {
        if (! auth()->user()?->isInstanceAdmin()) {
            abort(403);
        }

        $this->validate();

        try {
            $user = User::create([
                'name' => $this->name,
                'email' => $this->email,
                'password' => Hash::make($this->password),
                'email_verified_at' => now(),
            ]);

            $team = $user->teams->firstWhere('personal_team', true);
            if (! $team instanceof Team) {
                $team = $user->recreate_personal_team();
            }

            $team->update([
                'name' => $this->name,
                'personal_team' => true,
                'is_client' => true,
                'show_boarding' => false,
            ]);

            $this->reset(['name', 'email', 'password']);
            $this->refreshClients();
            $this->dispatch('success', 'Cliente creado.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    private function refreshClients(): void
    {
        $this->clients = Team::query()
            ->where('is_client', true)
            ->orderByRaw('LOWER(name)')
            ->get();
    }
}

