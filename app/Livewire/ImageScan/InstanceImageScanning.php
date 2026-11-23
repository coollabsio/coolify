<?php

namespace App\Livewire\ImageScan;

use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Instance settings page for image scanning: the default rules of every team.
 */
class InstanceImageScanning extends Component
{
    public function mount()
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }
    }

    public function render(): View
    {
        return view('livewire.image-scan.instance-image-scanning');
    }
}
