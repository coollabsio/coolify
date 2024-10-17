<?php

namespace App\Livewire\Notifications;

use App\Models\Team;
use App\Notifications\Test;
use Livewire\Component;

class External extends Component
{
    public Team $team;

    protected $rules = [
        'team.external_enabled' => 'nullable|boolean',
        'team.external_url' => 'required|url',
    ];

    public function mount()
    {
        $this->team = auth()->user()->currentTeam();
    }

    public function instantSave()
    {
        try {
            $this->submit();
        } catch (\Throwable $e) {
            ray($e->getMessage());
            $this->team->external_enabled = false;
            $this->validate();
        }
    }

    public function submit()
    {
        $this->resetErrorBag();
        $this->validate();
        $this->saveModel();
    }

    public function saveModel()
    {
        $this->team->save();
        refreshSession();
        $this->dispatch('success', 'Settings saved.');
    }

    public function sendTestNotification()
    {
        $this->team?->notify(new Test);
        $this->dispatch('success', 'Test notification sent.');
    }

    public function render()
    {
        return view('livewire.notifications.external');
    }
}
