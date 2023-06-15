<?php

namespace App\Http\Livewire;

use Masmerise\Toaster\Toaster;
use App\Jobs\InstanceAutoUpdateJob;
use Livewire\Component;

class Upgrade extends Component
{
    public bool $showProgress = false;
    public function upgrade()
    {
        try {
            $this->showProgress = true;
            dispatch(new InstanceAutoUpdateJob(force: true));
            Toaster::success('Update started.');
        } catch (\Exception $e) {
            return general_error_handler(err: $e, that: $this);
        }
    }
}
