<?php

namespace App\Livewire;

use App\Support\Dashboard\DashboardData;
use Illuminate\Support\Collection;
use Livewire\Component;

class Dashboard extends Component
{
    public Collection $projects;

    public Collection $servers;

    public Collection $privateKeys;

    public function mount()
    {
        $data = DashboardData::collections();
        $this->privateKeys = $data['privateKeys'];
        $this->servers = $data['servers'];
        $this->projects = $data['projects'];
    }

    public function render()
    {
        return view('livewire.dashboard');
    }
}
