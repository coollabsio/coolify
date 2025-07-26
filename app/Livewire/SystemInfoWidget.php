<?php

namespace App\Livewire;

use App\Services\SystemInfoService;
use Livewire\Component;

class SystemInfoWidget extends Component
{
    public array $info = [];

    public function render()
    {
        $this->info = app(SystemInfoService::class)->get();

        return view('livewire.system-info-widget');
    }
}
