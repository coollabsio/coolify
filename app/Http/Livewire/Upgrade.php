<?php

namespace App\Http\Livewire;

use App\Actions\Server\UpdateCoolify;
use Masmerise\Toaster\Toaster;
use Livewire\Component;

class Upgrade extends Component
{
    public bool $showProgress = false;
    public function upgrade()
    {
        try {
            $this->showProgress = true;
            resolve(UpdateCoolify::class)(true);
            Toaster::success('Update started.');
        } catch (\Exception $e) {
            return general_error_handler(err: $e, that: $this);
        }
    }
}
