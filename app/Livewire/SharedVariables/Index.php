<?php

namespace App\Livewire\SharedVariables;

use App\Services\SharedVariableCanvas;
use Illuminate\View\View;
use Livewire\Component;

class Index extends Component
{
    public function render(): View
    {
        // A "my team" page: the overview of the session team's shared variables (keys and counts only).
        return view('livewire.shared-variables.index', [
            'overview' => app(SharedVariableCanvas::class)->overview(currentTeam()),
        ]);
    }
}
