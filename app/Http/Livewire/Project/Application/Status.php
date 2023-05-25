<?php

namespace App\Http\Livewire\Project\Application;

use App\Models\Application;
use Livewire\Component;

class Status extends Component
{
    public Application $application;

    protected $listeners = [
        'applicationStatusChanged' => 'pollingStatus',
    ];
    public function pollingStatus()
    {
        $this->application->refresh();
    }
}
