<?php

namespace App\Http\Livewire;

use App\Jobs\InstanceAutoUpdateJob;
use App\Models\Server;
use Livewire\Component;

class ForceUpgrade extends Component
{
    public function upgrade()
    {
        try {
            $this->emit('updateInitiated');
            dispatch(new InstanceAutoUpdateJob(force: true));
        } catch (\Exception $e) {
            return general_error_handler($e, $this);
        }
    }
}
