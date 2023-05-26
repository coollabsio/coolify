<?php

namespace App\Http\Livewire;

use App\Jobs\InstanceAutoUpdateJob;
use Livewire\Component;

class ForceUpgrade extends Component
{
    public bool $visible = false;
    public function upgrade()
    {
        try {
            $this->visible = true;
            dispatch(new InstanceAutoUpdateJob(force: true));
        } catch (\Exception $e) {
            return general_error_handler($e, $this);
        }
    }
}
