<?php

namespace App\Livewire\Railway;

use App\Livewire\Railway\Concerns\LoadsProjectContext;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.railway')]
class Logs extends Component
{
    use LoadsProjectContext;

    public function mount(string $project_uuid, string $environment_uuid): void
    {
        $this->loadProjectContext($project_uuid, $environment_uuid);
    }

    public function render()
    {
        return view('livewire.railway.logs');
    }
}
