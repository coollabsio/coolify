<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use Livewire\Component;

use Illuminate\Support\Collection;
use Illuminate\Contracts\View\View;

use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Illuminate\Support\Facades\Gate;

class Backups extends Component
{
    #[Locked]
    public Application $application;

    public function mount(): void
    {
        abort_if(Gate::denies('view', $this->application), 403);
    }

    #[Computed]
    public function databases(): Collection
    {
        return $this->application->databases()->get();
    }

    public function render(): View
    {
        return view('livewire.project.application.backups');
    }
}
