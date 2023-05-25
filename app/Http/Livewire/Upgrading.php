<?php

namespace App\Http\Livewire;

use Livewire\Component;

class Upgrading extends Component
{
    public bool $visible = false;
    protected $listeners = ['updateInitiated'];
    public function updateInitiated()
    {
        $this->visible = true;
    }
}
