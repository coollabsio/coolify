<?php

namespace App\Http\Livewire;

use App\Jobs\InstanceAutoUpdateJob;
use Livewire\Component;

class ForceUpgrade extends Component
{
    public function upgrade()
    {
        $this->emit('updateInitiated');
        dispatch(new InstanceAutoUpdateJob(force: true));
    }
}
