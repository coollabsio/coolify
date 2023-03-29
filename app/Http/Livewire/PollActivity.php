<?php

namespace App\Http\Livewire;

use Livewire\Component;

class PollActivity extends Component
{
    public $activity;
    public $isKeepAliveOn = true;

    public function polling()
    {
        $this->activity?->refresh();
        if (data_get($this->activity, 'properties.exitCode') !== null) {
            $this->isKeepAliveOn = false;
        }
    }
    public function render()
    {
        return view('livewire.poll-activity');
    }
}
