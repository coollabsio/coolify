<?php

namespace App\Livewire;

use App\Models\UserRecentVisit;
use Livewire\Component;

class RecentlyVisited extends Component
{
    public $recentVisits = [];

    public function mount(): void
    {
        $this->loadRecentVisits();
    }

    public function loadRecentVisits(): void
    {
        if (auth()->check()) {
            $this->recentVisits = UserRecentVisit::getRecentForUser(auth()->id(), 5);
        }
    }

    public function render()
    {
        return view('livewire.recently-visited');
    }
}
