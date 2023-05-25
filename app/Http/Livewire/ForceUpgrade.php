<?php

namespace App\Http\Livewire;

use App\Jobs\InstanceAutoUpdateJob;
use Livewire\Component;

class ForceUpgrade extends Component
{
    public function upgrade()
    {
        dispatch_sync(new InstanceAutoUpdateJob(force: true));
        $this->emit('updateInitiated');
    }
}
