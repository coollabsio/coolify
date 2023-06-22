<?php

namespace App\Http\Livewire;

use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class License extends Component
{
    public string $license;
    public function submit()
    {
        ray('checking license');
        $this->validate([
            'license' => 'required'
        ]);
        // Pretend we're checking the license
        // if ($this->license === '123') {
        //     ray('license is valid');
        //     Cache::put('license_key', '123');
        //     return redirect()->to('/');
        // } else {
        //     ray('license is invalid');
        // }
    }
}
