<?php

namespace App\Http\Livewire;

use App\Models\Application;
use Livewire\Component;

class TemporaryCheckStatus extends Component
{
    public $application_id;

    public function checkStatus() {
           dd(Application::find(1)->environments);
    }
    public function render()
    {
        return view('livewire.temporary-check-status');
    }
}
