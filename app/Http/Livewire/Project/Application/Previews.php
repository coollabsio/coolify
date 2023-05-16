<?php

namespace App\Http\Livewire\Project\Application;

use App\Models\Application;
use Livewire\Component;

class Previews extends Component
{
    public Application $application;
    public function render()
    {
        return view('livewire.project.application.previews');
    }
}
